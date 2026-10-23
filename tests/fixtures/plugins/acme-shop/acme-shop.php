<?php
/**
 * A stand-in plugin file: the capability history reads the call stack to tell
 * which plugin added a capability, so the call has to come from a file inside
 * a plugins folder.
 *
 * @package AryanX\RoleRightSizer
 */

// phpcs:ignoreFile

return static function ( callable $callback ) {
	return $callback();
};
