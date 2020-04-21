<?php

namespace MediaWiki\Hook;

/**
 * @stable for implementation
 * @ingroup Hooks
 */
interface ContributionsLineEndingHook {
	/**
	 * Called before a contributions HTML line is finished
	 *
	 * @since 1.35
	 *
	 * @param ?mixed $page SpecialPage object for contributions
	 * @param ?mixed &$ret the HTML line
	 * @param ?mixed $row the DB row for this line
	 * @param ?mixed &$classes the classes to add to the surrounding <li>
	 * @param ?mixed &$attribs associative array of other HTML attributes for the <li> element.
	 *   Currently only data attributes reserved to MediaWiki are allowed
	 *   (see Sanitizer::isReservedDataAttribute).
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onContributionsLineEnding( $page, &$ret, $row, &$classes,
		&$attribs
	);
}
