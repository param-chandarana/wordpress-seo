import { createSlice } from "@reduxjs/toolkit";
import apiFetch from "@wordpress/api-fetch";
import { get } from "lodash";

export const MYYOAST_CONNECTION_NAME = "myyoastConnection";

const REQUEST_TIMEOUT_MS = 30000;

const ENDPOINTS = {
	refreshStatus: { actionType: "refreshMyyoastConnectionStatus", method: "POST", path: "/yoast/v1/myyoast/refresh-status" },
	connect: { actionType: "connectMyyoastConnection", method: "POST", path: "/yoast/v1/myyoast/register" },
	update: { actionType: "updateMyyoastConnection", method: "PUT", path: "/yoast/v1/myyoast/registration" },
	disconnect: { actionType: "disconnectMyyoastConnection", method: "DELETE", path: "/yoast/v1/myyoast/registration" },
	authorize: { actionType: "authorizeMyyoastSite", method: "POST", path: "/yoast/v1/myyoast/authorize" },
};

/**
 * @returns {Object} The initial myyoastConnection state.
 */
export const getInitialMyyoastConnectionState = () => ( {
	status: null,
	profileUrl: "",
	actionInFlight: null,
	actionError: null,
	pendingCallbackOutcome: null,
	linkParams: {},
} );

const DEFAULT_STATUS = {
	isProvisioned: false,
	isRegistered: false,
	registeredAt: null,
	registeredAtIso: null,
	redirectUris: [],
	redirectUrisMatch: true,
};

/**
 * Transforms a snake_case status payload from the backend into the camelCase
 * shape used inside the React app.
 *
 * @param {Object} payload The backend payload.
 * @returns {Object} The camelCase status.
 */
export const transformStatus = ( payload ) => {
	if ( ! payload ) {
		return DEFAULT_STATUS;
	}
	const redirectUris = Array.isArray( payload.redirect_uris )
		? payload.redirect_uris.map( ( entry ) => ( {
			uri: entry?.uri ?? "",
			origin: entry?.origin ?? "",
			isVerified: Boolean( entry?.is_verified ),
		} ) )
		: [];
	return {
		isProvisioned: Boolean( payload.is_provisioned ),
		isRegistered: Boolean( payload.is_registered ),
		registeredAt: payload.registered_at ?? null,
		registeredAtIso: payload.registered_at_iso ?? null,
		redirectUris,
		redirectUrisMatch: payload.redirect_uris_match !== false,
	};
};

const slice = createSlice( {
	name: MYYOAST_CONNECTION_NAME,
	initialState: getInitialMyyoastConnectionState(),
	reducers: {
		setMyyoastStatus: ( state, { payload } ) => {
			if ( payload ) {
				state.status = payload;
			}
		},
		startMyyoastAction: ( state, { payload } ) => {
			// Guard against starting a second action while one is already running:
			// every action mutates the same registration, so they must serialize.
			if ( state.actionInFlight ) {
				return;
			}
			state.actionInFlight = payload;
			state.actionError = null;
		},
		setMyyoastActionError: ( state, { payload } ) => {
			state.actionError = payload;
		},
		finishMyyoastAction: state => {
			state.actionInFlight = null;
		},
		clearMyyoastCallbackOutcome: state => {
			state.pendingCallbackOutcome = null;
		},
	},
} );

const { setMyyoastStatus, startMyyoastAction, setMyyoastActionError, finishMyyoastAction } = slice.actions;

/**
 * Builds a generator action that performs a MyYoast management request through
 * the matching control, mirrors the response into the slice, and returns a
 * result object the caller can use to drive UI notifications.
 *
 * @param {string} name The action name (refreshStatus/connect/update/disconnect).
 * @returns {GeneratorFunction} The generator action.
 */
// eslint-disable-next-line complexity
const createMyyoastAction = ( name ) => function* ( body ) {
	yield startMyyoastAction( name );
	try {
		const payload = yield{ type: ENDPOINTS[ name ].actionType, payload: body };
		if ( payload?.status ) {
			yield setMyyoastStatus( transformStatus( payload.status ) );
		}
		// The backend signals failure with `error_code` in the body — HTTP status
		// stays 200 for upstream/precondition failures.
		if ( payload?.error_code ) {
			yield setMyyoastActionError( { actionName: name, errorCode: payload.error_code, message: "" } );
			return { ok: false, errorCode: payload.error_code, details: payload.details };
		}
		return { ok: true, messageKey: payload?.message_key };
	} catch ( error ) {
		const errorCode = error?.name === "AbortError" ? "timeout" : "unexpected_error";
		yield setMyyoastActionError( { actionName: name, errorCode, message: "" } );
		return { ok: false, errorCode };
	} finally {
		yield finishMyyoastAction();
	}
};

const refreshMyyoastConnectionStatus = createMyyoastAction( "refreshStatus" );
const connectMyyoastConnection = createMyyoastAction( "connect" );
const updateMyyoastConnection = createMyyoastAction( "update" );
const disconnectMyyoastConnection = createMyyoastAction( "disconnect" );

/**
 * Starts the authorization-code flow for the site's registration.
 *
 * The backend resolves which registered redirect URI to use, so no URI is
 * sent. The optional `returnUrl` tells the backend where to send the browser
 * once the flow completes — pass the page the flow was started from, since the
 * flow can be kicked off from several admin pages. It is validated server-side
 * and ignored when off-site or invalid. On success the action returns an
 * `authorize_url` the browser should be navigated to; the caller decides how to
 * do that. On failure the slice's actionError is set, mirroring the other actions.
 *
 * @param {Object} [options] The action options.
 * @param {string} [options.returnUrl] The URL to return to after the flow completes.
 * @returns {GeneratorFunction} The generator action.
 */
// eslint-disable-next-line complexity
const authorizeMyyoastSite = function* ( { returnUrl } = {} ) {
	yield startMyyoastAction( "authorize" );
	try {
		// eslint-disable-next-line camelcase -- snake_case matches the REST endpoint's request contract.
		const body = returnUrl ? { return_url: returnUrl } : {};
		const payload = yield{ type: ENDPOINTS.authorize.actionType, payload: body };
		if ( payload?.status ) {
			yield setMyyoastStatus( transformStatus( payload.status ) );
		}
		if ( payload?.error_code ) {
			yield setMyyoastActionError( { actionName: "authorize", errorCode: payload.error_code, message: "" } );
			return { ok: false, errorCode: payload.error_code, details: payload.details };
		}
		if ( ! payload?.authorize_url ) {
			yield setMyyoastActionError( { actionName: "authorize", errorCode: "unexpected_error", message: "" } );
			return { ok: false, errorCode: "unexpected_error" };
		}
		return { ok: true, authorizeUrl: payload.authorize_url };
	} catch ( error ) {
		const errorCode = error?.name === "AbortError" ? "timeout" : "unexpected_error";
		yield setMyyoastActionError( { actionName: "authorize", errorCode, message: "" } );
		return { ok: false, errorCode };
	} finally {
		yield finishMyyoastAction();
	}
};

/**
 * Calls a MyYoast endpoint via apiFetch with a client-side timeout.
 *
 * @param {Object} endpoint The endpoint config.
 * @param {Object} body     The request body.
 * @returns {Promise<Object>} The parsed response payload.
 */
const callEndpoint = async( endpoint, body ) => {
	const controller = new AbortController();
	const timeoutId = setTimeout( () => controller.abort(), REQUEST_TIMEOUT_MS );
	try {
		return await apiFetch( {
			method: endpoint.method,
			path: endpoint.path,
			data: body,
			signal: controller.signal,
		} );
	} finally {
		clearTimeout( timeoutId );
	}
};

export const myyoastConnectionActions = {
	...slice.actions,
	refreshMyyoastConnectionStatus,
	connectMyyoastConnection,
	updateMyyoastConnection,
	disconnectMyyoastConnection,
	authorizeMyyoastSite,
};

export const myyoastConnectionControls = {
	[ ENDPOINTS.refreshStatus.actionType ]: ( { payload } ) => callEndpoint( ENDPOINTS.refreshStatus, payload ),
	[ ENDPOINTS.connect.actionType ]: ( { payload } ) => callEndpoint( ENDPOINTS.connect, payload ),
	[ ENDPOINTS.update.actionType ]: ( { payload } ) => callEndpoint( ENDPOINTS.update, payload ),
	[ ENDPOINTS.disconnect.actionType ]: ( { payload } ) => callEndpoint( ENDPOINTS.disconnect, payload ),
	[ ENDPOINTS.authorize.actionType ]: ( { payload } ) => callEndpoint( ENDPOINTS.authorize, payload ),
};

export const myyoastConnectionSelectors = {
	selectMyyoastConnectionStatus: state => get( state, "myyoastConnection.status", DEFAULT_STATUS ) ?? DEFAULT_STATUS,
	selectMyyoastConnectionProfileUrl: state => get( state, "myyoastConnection.profileUrl", "" ),
	selectMyyoastConnectionActionInFlight: state => get( state, "myyoastConnection.actionInFlight", null ),
	selectMyyoastConnectionActionError: state => get( state, "myyoastConnection.actionError", null ),
	selectMyyoastConnectionPendingCallbackOutcome: state => get( state, "myyoastConnection.pendingCallbackOutcome", null ),
	selectMyyoastConnectionLinkParams: state => get( state, "myyoastConnection.linkParams", {} ),
	selectHasMyyoastConnection: state => get( state, "myyoastConnection.status", null ) !== null,
};

export const myyoastConnectionReducer = slice.reducer;
