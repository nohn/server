<?php

/**
 * Class BadClass - accessing consts on blacklisted classes is not allowed
 */
class BadClass {
	public function foo() {
		$bar = \OCP\API::RESPOND_UNKNOWN_ERROR;
	}
}
