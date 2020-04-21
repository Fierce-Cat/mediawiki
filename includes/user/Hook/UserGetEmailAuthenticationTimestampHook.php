<?php

namespace MediaWiki\User\Hook;

/**
 * @stable for implementation
 * @ingroup Hooks
 */
interface UserGetEmailAuthenticationTimestampHook {
	/**
	 * Called when getting the timestamp of
	 * email authentication.
	 *
	 * @since 1.35
	 *
	 * @param ?mixed $user User object
	 * @param ?mixed &$timestamp timestamp, change this to override local email authentication
	 *   timestamp
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onUserGetEmailAuthenticationTimestamp( $user, &$timestamp );
}
