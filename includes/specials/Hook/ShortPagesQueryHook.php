<?php

namespace MediaWiki\Hook;

/**
 * @stable for implementation
 * @ingroup Hooks
 */
interface ShortPagesQueryHook {
	/**
	 * Allow extensions to modify the query used by
	 * Special:ShortPages.
	 *
	 * @since 1.35
	 *
	 * @param ?mixed &$tables tables to join in the query
	 * @param ?mixed &$conds conditions for the query
	 * @param ?mixed &$joinConds join conditions for the query
	 * @param ?mixed &$options options for the query
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onShortPagesQuery( &$tables, &$conds, &$joinConds, &$options );
}
