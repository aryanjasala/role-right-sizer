# Reference

## How usage is recorded

Everything is collected in memory during a request and written once, on `shutdown`. The same capability is written at most once an hour per user (`role_right_sizer_record_interval`), so a busy editor costs a few user meta writes an hour. Only capabilities the user really has are recorded, so a hook that fires for everyone cannot make a role look needed.

| Source | Counts as |
| --- | --- |
| Mapped hooks | The capability the event proves, worked out the way core's `map_meta_cap()` decides it. Editing someone else's published post is `edit_others_posts` and `edit_published_posts`; publishing is the post type's `publish_posts`. The full map is below |
| Admin screens | The capability the screen's menu entry needs, looked up in `$menu` and `$submenu` the way `user_can_access_admin_page()` does |
| REST API | Requests that change something (not `GET`, `HEAD` or `OPTIONS`) and succeed: every capability check that passed while the route's permission callback ran. Checks made while the response is built, such as which buttons the editor may show, are left out |
| Abilities | Every ability run, by name, on WordPress 6.9 and later |
| Logins and activity | The last login (network-wide) and the last logged-in request on the site, at most once an hour |

The default hook map:

| Hook | Capability |
| --- | --- |
| `transition_post_status` | the post type's `publish_posts`, when a post is published or scheduled |
| `save_post` | the post type's `edit_posts`, `edit_others_posts`, `edit_published_posts` or `edit_private_posts`, from the status before the save |
| `wp_trash_post`, `before_delete_post` | the post type's delete capabilities, the same way |
| `add_attachment` | `upload_files` |
| `activated_plugin`, `deactivated_plugin` | `activate_plugins` |
| `upgrader_process_complete` | `install_plugins`, `update_plugins`, `install_themes`, `update_themes`, `update_core` or `install_languages` |
| `switch_theme` | `switch_themes` |
| `user_register`, `profile_update`, `delete_user`, `remove_user_from_blog` | `create_users`, `edit_users`, `delete_users`, `remove_users`, when the account is someone else's |
| `set_user_role` | `promote_users`, when someone else's existing account changes role |
| `allowed_options` | the settings page capability (`manage_options` by default), on a save with a valid nonce |
| `created_term`, `edit_terms`, `pre_delete_term` | the taxonomy's edit or delete capability, mapped to primitives (`manage_categories` for categories and tags) |
| `transition_comment_status` | `moderate_comments` |
| `customize_save_after` | what `customize` maps to (`edit_theme_options`) |
| `export_wp` | `export` |
| `import_start` | `import` |
| `wp_update_nav_menu` | `edit_theme_options` |

## How recommendations are made

Recommendations stay hidden until tracking has run for the learning period (30 days). A capability counts as used if its last use is inside the window (90 days). Only users with a role that grants more than `read` are looked at.

- **Smaller role** (`downgrade`): the role with the fewest capabilities that covers everything the user used. It only counts roles whose capabilities the user already has, so a recommendation never gives more access, and a role without `read` never replaces one with it. Roles are compared by how many capabilities they grant; equal sizes keep the site's order.
- **Dormant** (`dormant`): no login or activity for 90 days. The recommendation is no role on this site, or the fallback role from the settings screen. A fallback role that gives more than the user has now is skipped.
- **Never logged in** (`never`): no login or activity since tracking began, or since the account was created if that was later, once the learning period has passed since then.
- **Unused capability on a role** (`unused_cap`): a capability a role grants that no user with that role used in the window. Core capabilities are never suggested, only the ones plugins and custom roles add. On top of that:
  - Protected roles are skipped: `administrator` and every role that grants `manage_options` (`role_right_sizer_protected_roles`). Plugins put their own screens behind capabilities on those roles, and removing one can lock every administrator out. Users with these roles still get a smaller role when they qualify.
  - A capability only counts once it has been on the role since the start of the window, or since tracking began if that is later, and for at least a whole learning period. One a plugin added last week never had the chance to be used. The capability history below records when each role gained each capability. A capability put back with Undo counts from that moment too, so a suggestion you undid does not come back for a whole window.
  - Each one says who it belongs to, when that can be told: the plugin that added it (read from the call stack when the role changed), a registered post type or taxonomy that checks it, or an active plugin whose folder name is in the capability name (`manage_woocommerce`). Filter it with `role_right_sizer_capability_owner`. The screen groups them by role and owner, and `apply --all` leaves the ones of an active owner alone unless you add `--include-plugin-caps`.

Site Health only makes it a security recommendation when accounts have more access than they use. Leftover capabilities on roles are mentioned as housekeeping: a capability no code checks grants nothing. Capabilities of an active owner are not counted.

The screen also says how many accounts it did not check and why: accounts that have not logged in yet but are younger than the learning period are not flagged yet, and accounts with nothing beyond `read` have nothing to make smaller (`role_right_sizer_skipped_lines`).

Never touched: users and roles on the exclude list, super admins on multisite, your own account, and the last administrator (the last user with a role that grants `manage_options`). Capabilities granted straight to a user, not through a role, are left alone.

The report is built 100 users at a time. A build runs up to 20 batches in the request that asks for it and the rest through WP-Cron, and a daily event rebuilds it. Opening the screen rebuilds it when it is more than a day old.

## Hooks

| Hook | Type | Use |
| --- | --- | --- |
| `role_right_sizer_capability` | filter | `( string $capability )`. Who may see and apply recommendations, on the screen, the CSV and the abilities. Default `promote_users` |
| `role_right_sizer_hook_capability_map` | filter | `( array $map )`. Hook name => capability, list of capabilities, or a callable that gets the hook's arguments and returns capabilities. Read on `init` |
| `role_right_sizer_screen_capability` | filter | `( string $cap, WP_Screen\|null $screen )`. The capability an admin screen visit counts as. `''` records nothing |
| `role_right_sizer_ignored_capabilities` | filter | `( string[] $caps )`. Never counted as used, lost or unused. Default `exist` and `level_0` to `level_10` |
| `role_right_sizer_ignored_post_types` | filter | `( string[] $types )`. Post types whose saves and deletes are not recorded. Default revisions, oEmbed caches, changesets, privacy requests and menu items |
| `role_right_sizer_record_interval` | filter | `( int $seconds )`. How often the same capability is written for the same user. Default 3600, 0 to 604800 |
| `role_right_sizer_learning_days` | filter | `( int $days )`. Days of recording before recommendations show up. Default 30, 0 to 3650 |
| `role_right_sizer_window_days` | filter | `( int $days )`. How far back a capability counts as used. Default 90 |
| `role_right_sizer_dormant_days` | filter | `( int $days )`. Days without activity before an account is dormant. Default 90 |
| `role_right_sizer_dormant_action` | filter | `( string $action, WP_User $user )`. `remove` (no role on this site) or `fallback`. Anything else is `remove` |
| `role_right_sizer_fallback_role` | filter | `( string $role, WP_User $user )`. The role dormant accounts move to. A role that does not exist means no role |
| `role_right_sizer_candidate_roles` | filter | `( string[] $roles, WP_User $user )`. Roles that may be recommended for a user. Default every role |
| `role_right_sizer_excluded_users` | filter | `( int[] $user_ids )`. Users never to touch. Default the list on the settings screen |
| `role_right_sizer_excluded_roles` | filter | `( string[] $roles )`. Roles never to touch: their users keep them, and the role keeps its capabilities |
| `role_right_sizer_core_capabilities` | filter | `( string[] $caps )`. Capabilities never suggested for removal from a role. Default every capability core gives its own roles |
| `role_right_sizer_protected_roles` | filter | `( string[] $roles )`. Roles whose capabilities are never suggested for removal. Default `administrator` and every role that grants `manage_options`. Return fewer to have their unused capabilities listed too. Applying is checked against it again |
| `role_right_sizer_capability_owner` | filter | `( array\|null $owner, string $cap, string $role )`. Who a capability on a role belongs to: `name`, `active`, and optionally `type` and `id`. Return null when unknown. Active owners are marked and left out of `apply --all` |
| `role_right_sizer_skipped_lines` | filter | `( string[] $lines, array $report )`. The lines under the toolbar that say which accounts were not checked and why |
| `role_right_sizer_site_health_result` | filter | `( array $result )`. The Site Health result. A `status` other than good, recommended or critical is ignored |
| `role_right_sizer_recommendation` | filter | `( array $recommendation, WP_User $user )`. Return null to drop it, or change `proposed` to another role. The lost capabilities are worked out again, and the last administrator check still runs after it |
| `role_right_sizer_build_batch_size` | filter | `( int $size )`. Users per batch of the report build. Default 100, 10 to 500 |
| `role_right_sizer_log_size` | filter | `( int $size )`. Changes the log keeps. Default 500, 10 to 5000 |
| `role_right_sizer_capability_used` | action | `( string $cap, int $user_id, string $source )`. The first time in a request that a user uses a capability. `$source` is the hook name, `screen` or `rest` |
| `role_right_sizer_recommendations_built` | action | `( array $report )`. A report build finished. `users` holds the recommendations by user ID, `roles` the unused capabilities |
| `role_right_sizer_before_apply` | action | `( array $change, WP_User\|null $user )`. Right before a role change, or before a capability is removed from a role (`$user` is null then) |
| `role_right_sizer_applied` | action | `( array $change )`. A change was made and logged. `$change['id']` undoes it |
| `role_right_sizer_undone` | action | `( array $change )`. A change was undone |
| `role_right_sizer_usage_forgotten` | action | `( int $user_id )`. A user's usage was deleted, by `wp role-right-sizer forget` or the personal data eraser |

```php
// WooCommerce: an order edited in wp-admin proves the shop manager capability.
add_filter( 'role_right_sizer_hook_capability_map', function ( $map ) {
	$map['woocommerce_update_order'] = 'edit_shop_orders';
	return $map;
} );

// A quarterly task: count a capability as used for a year, and learn for 90 days first.
add_filter( 'role_right_sizer_window_days', fn() => 365 );
add_filter( 'role_right_sizer_learning_days', fn() => 90 );

// Never recommend a custom role nobody should be moved to.
add_filter( 'role_right_sizer_candidate_roles', fn( $roles ) => array_diff( $roles, array( 'shop_manager' ) ) );

// Tell the security channel about every change.
add_action( 'role_right_sizer_applied', function ( $change ) {
	my_security_alert( sprintf( 'Role Right-Sizer change %d: %s', $change['id'], wp_json_encode( $change ) ) );
} );
```

A change is an array with `id`, `batch`, `time`, `by` (user ID, 0 for WP-CLI), `kind` (`downgrade`, `dormant`, `never` or `unused_cap`), `lost`, `undone`, and either `user_id`, `login`, `before` and `after` (role lists) or `role` and `cap`.

### JavaScript hooks

The recommendations screen has a small script for the review bar and the group checkboxes. Its hooks go through `wp.hooks` and are read on each use, so a script loaded later can add them. Select all on the tables is core's own list table script.

| Hook | Type | Use |
| --- | --- | --- |
| `roleRightSizer.reviewLabel` | filter | `( label, count, form )`. The text of the review button for this many selected changes |
| `roleRightSizer.selectionChanged` | action | `( count, form )`. What is ticked changed |

```js
wp.hooks.addFilter( 'roleRightSizer.reviewLabel', 'my-plugin', ( label, count ) => ( count > 20 ? label + ' (that is a lot)' : label ) );
```

## Abilities

On WordPress 6.9 and later, Role Right-Sizer registers [abilities](https://developer.wordpress.org/apis/abilities/), which AI agents find and run through the [MCP adapter](https://github.com/WordPress/mcp-adapter) and anything else through the REST API under `/wp-abilities/v1/`. Each one is marked public, which the MCP adapter needs before it lists an ability. All four need the Role Right-Sizer capability, and the same protections apply as on the screen.

| Ability | Does |
| --- | --- |
| `role-right-sizer/get-recommendations` | Read-only. `learning`, `learning_ends`, `built`, the user recommendations (`login`, `type`, `current`, `proposed`, `lost`, `last_active`) and the unused capabilities on roles (`role`, `cap`, `users`, `owner`, `owner_active`) |
| `role-right-sizer/get-user-usage` | Read-only. For `user` (ID, username or email): every capability and ability with first and last use and whether it is in the window, last login and activity, and the recommendation |
| `role-right-sizer/apply-recommendation` | Destructive, so the REST API runs it with `POST`. Applies the recommendation for `user_id`, or removes `capability` from `role`. Returns the logged change. Refused (400) for your own account, the last administrator and the exclude list |
| `role-right-sizer/undo-change` | Destructive. Undoes `change_id`, or its whole batch with `batch: true` |

When [Agent Governance](https://github.com/aryanjasala/agent-governance) holds an agent's call to `apply-recommendation` or `undo-change`, Role Right-Sizer fills in its preview through `agent_governance_dry_run`: who moves from which role to which, the capabilities lost, or what an undo puts back. Nothing changes while the preview is built.

## WP-CLI

```sh
wp role-right-sizer report [--all-users] [--cached] [--format=table|csv|json|yaml|count]
wp role-right-sizer usage <user> [--format=...]
wp role-right-sizer apply <user>... [--dry-run]
wp role-right-sizer apply --all [--include-plugin-caps] [--dry-run] [--yes]
wp role-right-sizer changes [--format=...]
wp role-right-sizer undo <change-id> [--batch]
wp role-right-sizer forget <user>
```

`report` builds the recommendations first unless `--cached` is given. `--all-users` lists every user in the columns of the CSV download. `report` lists unused capabilities with their `owner` and `owner_active`. `apply --all` applies every user and role recommendation as one batch, except capabilities that belong to an active plugin, post type or taxonomy; it names those and leaves them alone unless you add `--include-plugin-caps`. Users can be IDs, usernames or email addresses. WP-CLI runs without a logged-in user unless you pass `--user`, so the "not your own account" rule only applies with `--user`. The last administrator rule always applies.

Settings live in the `role_right_sizer_settings` option: `wp option update role_right_sizer_settings '{"dormant_action":"fallback","fallback_role":"subscriber","excluded_users":[],"excluded_roles":[]}' --format=json`.

## PHP API

```php
$plugin = AryanX\RoleRightSizer\Plugin::instance();

$plugin->recorder()->record( 'edit_shop_orders', $user_id, 'my-plugin' ); // count a capability as used now
$plugin->store()->usage( $user_id );            // [ cap => [ first_used, last_used, count ] ]
$plugin->report()->rebuild();                   // build now, the rest in the background
$plugin->report()->users();                     // the user recommendations
$plugin->history()->current();                  // [ role => [ cap => [ first_seen, plugin_folder ] ] ]
$plugin->applier()->apply_many( array( 12 ), array( 'shop_editor|view_reports' ) ); // [ 'batch', 'changes', 'errors' ]
$plugin->applier()->undo_batch( $batch );
```

## Data

| Where | Contents |
| --- | --- |
| `{prefix}role_right_sizer_usage` user option | Capabilities used: `cap => [ first_used, last_used, count ]`. `count` is how many times it was written, at most once an hour |
| `{prefix}role_right_sizer_abilities` user option | Abilities run, in the same shape |
| `{prefix}role_right_sizer_last_active` user option | The last logged-in request on the site |
| `role_right_sizer_last_login` user meta | The last login, network-wide |
| `role_right_sizer_tracking_started` option | When tracking began on the site (autoloaded, read on every request) |
| `role_right_sizer_settings` option | Dormant action, fallback role, excluded users and roles |
| `role_right_sizer_report` option | The last report and the state of a build in progress |
| `role_right_sizer_changes` option | The change log, newest first, at most 500 changes |
| `role_right_sizer_capability_history` option | When each role gained each capability, and the plugin folder that added it when known: `role => [ cap => [ first_seen, folder ] ]`. Updated when the roles change and on every report build. Not autoloaded |

`{prefix}` is the site's table prefix, such as `wp_` or `wp_2_`. The cron events are `role_right_sizer_daily` and `role_right_sizer_build`. Deactivating stops them. Uninstalling removes all of the above on every site of a network. Roles and capabilities stay as they are: applied changes are not undone.

## Privacy

The usage record and the change log are personal data: when someone was active and what they did. They take part in **Tools > Export Personal Data** and **Tools > Erase Personal Data**. Erasing deletes the usage record and the changes to that person's account, and keeps changes they made without their name. **Settings > Privacy** gets suggested text.

## Multisite

Roles are per site, so usage, recommendations and the change log are too. Super admins get no recommendations, because they can do everything on every site. **Network Admin > Users > Right-Sizer** lists each super admin with their last login and, per site, their last activity and how many capabilities they used.

## Limits

- Hooks only see what WordPress or a plugin does through them. A plugin screen whose saves fire no mapped hook and go through `admin-post.php` or admin-ajax is counted only by the screen visit. Add its hook to the map.
- A capability used less often than the window (a yearly export) looks unused. Widen the window or exclude the account.
- Capability checks in REST callbacks after the permission check are not recorded, on purpose. A route that checks permissions inside its callback instead counts only through the mapped hooks.
