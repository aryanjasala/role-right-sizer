# Reference

## Trials

**Try for 14 days** sits next to Apply on every smaller-role recommendation, and on the review step. It gives the person the recommended role the same way Apply does, through the change log, so Undo works. Only accounts that are still in use can be tried (`role_right_sizer_trial_types`), and a person has one open trial at a time.

While a trial is open, only that person is watched, and only for refusals that mean something:

| What happened | Shown as |
| --- | --- |
| An admin screen the menu refused (`admin_page_access_denied`) | "Tried to open Plugins" |
| A `wp_die()` with a 401 or 403 after a capability check failed, in a page or an AJAX call | "Could not edit a page by another author" |
| A REST request that changes something and was answered 401 or 403 | "Could not create categories through the REST API", or the capability words when a check failed |

Menus that hide entries, checks that decide what to show, `GET` requests and an expired nonce are not walls. The same wall is grouped and counted ("3 times, last 2 hours ago"). Nothing is written while the request runs: the refusals are saved on `shutdown`.

At a wall the person sees "Your access was reduced 3 days ago as a trial. Ask for it back." with a **Request my access back** button. It emails whoever started the trial (or the site admin address for WP-CLI) once a day at most. REST errors carry the same sentence under `role_right_sizer_trial`.

On **Users > Right-Sizer > Trials** you can restore the old role at any time, extend the trial, or make the smaller role permanent. Restore and Make permanent ask first, in place. When the time is up the person keeps the smaller role and the trial waits for a decision, and whoever started it gets an email. With the setting "Make the smaller role permanent" a trial that ended with no denials closes on its own. A trial with denials is never closed on its own. Undoing the change from the Changes tab also closes the trial. Site Health notes trials that wait for a decision.

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
| `role_right_sizer_capability` | filter | `( string $capability )`. Who may open the screen, the CSV and the abilities. Default `promote_users`. Details about one account also need `edit_user` for that account, and changing it needs `promote_user` |
| `role_right_sizer_can_view_user` | filter | `( bool $allowed, int $user_id )`. Whether the current user may see capabilities, usage, changes and trials of an account. Default whether they may edit it. WP-CLI always may |
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
| `role_right_sizer_trial_days` | filter | `( int $days )`. How long a trial lasts, and how many days an extension adds. Default 14, 1 to 365 |
| `role_right_sizer_trial_types` | filter | `( string[] $types )`. Recommendation types that can be tried first. Default `downgrade`. Others: `dormant`, `never` |
| `role_right_sizer_trial_end_action` | filter | `( string $action )`. What happens when a trial ends with no denials: `ask` (default) or `keep`. A trial with denials is never closed on its own |
| `role_right_sizer_trial_denial` | filter | `( array\|null $denial, array\|null $trial )`. A refusal before it is recorded: `key`, `kind` (`screen`, `rest`, `action`), `label`, `cap`. Return null to leave it out |
| `role_right_sizer_trial_denial_limit` | filter | `( int $limit )`. Different denials one trial keeps, oldest first out. Default 50, 5 to 500 |
| `role_right_sizer_trial_cap_labels` | filter | `( string[] $labels )`. Capability => words for what the person could not do |
| `role_right_sizer_trial_screen_titles` | filter | `( string[] $titles )`. Admin file or plugin page => name used in "Tried to open ..." |
| `role_right_sizer_trial_message` | filter | `( string $text, array\|null $trial, bool $html )`. The sentence the person sees at a wall. Default "Your access was reduced ... as a trial. Ask for it back." |
| `role_right_sizer_trial_wall` | filter | `( string $html, array $trial )`. The HTML added to the page they see, with the request button |
| `role_right_sizer_trial_request_interval` | filter | `( int $seconds )`. How long the person waits before asking again. Default one day, 0 to 30 days |
| `role_right_sizer_trial_recipients` | filter | `( string[] $emails, array $trial )`. Who is emailed about a trial. Default whoever started it, or the site admin address |
| `role_right_sizer_trial_email` | filter | `( array\|false $email, string $event, array $trial )`. The email (`to`, `subject`, `message`) for `access_requested` or `ended`. Return false to send nothing |
| `role_right_sizer_trial_retention_days` | filter | `( int $days )`. How long a closed trial and its denials are kept. Default 365 |
| `role_right_sizer_capability_used` | action | `( string $cap, int $user_id, string $source )`. The first time in a request that a user uses a capability. `$source` is the hook name, `screen` or `rest` |
| `role_right_sizer_recommendations_built` | action | `( array $report )`. A report build finished. `users` holds the recommendations by user ID, `roles` the unused capabilities |
| `role_right_sizer_before_apply` | action | `( array $change, WP_User\|null $user )`. Right before a role change, or before a capability is removed from a role (`$user` is null then) |
| `role_right_sizer_applied` | action | `( array $change )`. A change was made and logged. `$change['id']` undoes it |
| `role_right_sizer_undone` | action | `( array $change )`. A change was undone |
| `role_right_sizer_usage_forgotten` | action | `( int $user_id )`. A user's usage was deleted, by `wp role-right-sizer forget` or the personal data eraser |
| `role_right_sizer_trial_started` | action | `( array $trial )`. A trial started. The person already has the smaller role |
| `role_right_sizer_trial_denied` | action | `( array $denial, array $trial )`. A person on trial hit a wall |
| `role_right_sizer_trial_access_requested` | action | `( array $trial )`. The person asked for their access back |
| `role_right_sizer_trial_ended` | action | `( array $trial )`. A trial's time is up and it waits for a decision |
| `role_right_sizer_trial_extended` | action | `( array $trial, int $days )`. A trial was extended |
| `role_right_sizer_trial_kept` | action | `( array $trial, bool $auto )`. The smaller role was made permanent. `$auto` is true when Right-Sizer did it because there were no denials |
| `role_right_sizer_trial_restored` | action | `( array $trial )`. The old role was given back and the trial closed |

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

A trial is an array with `id`, `user_id`, `login`, `by`, `started`, `ends`, `change_id` and `batch` (the change in the log, so Undo works), `before`, `after`, `lost`, `status` (`active`, `ended`, `kept` or `restored`), `closed`, `auto`, `requested` and `denials` (`key => [ kind, label, cap, count, first, last ]`).

A change is an array with `id`, `batch`, `time`, `by` (user ID, 0 for WP-CLI), `kind` (`downgrade`, `dormant`, `never` or `unused_cap`), `lost`, `undone`, and either `user_id`, `login`, `before` and `after` (role lists) or `role` and `cap`.

### JavaScript hooks

The recommendations screen has a small script for the review bar and the group checkboxes. Its hooks go through `wp.hooks` and are read on each use, so a script loaded later can add them. Select all on the tables is core's own list table script.

| Hook | Type | Use |
| --- | --- | --- |
| `roleRightSizer.reviewLabel` | filter | `( label, count, form )`. The text of the review button for this many selected changes |
| `roleRightSizer.selectionChanged` | action | `( count, form )`. What is ticked changed |
| `roleRightSizer.announce` | filter | `( message, notice )`. The text read out to screen readers when a result notice shows. An empty string says nothing |
| `roleRightSizer.confirmOpened` | action | `( details )`. An in-place confirmation (Undo, Restore now, Make permanent) opened |
| `roleRightSizer.confirmClosed` | action | `( details )`. It closed |

```js
wp.hooks.addFilter( 'roleRightSizer.reviewLabel', 'my-plugin', ( label, count ) => ( count > 20 ? label + ' (that is a lot)' : label ) );
```

## Abilities

On WordPress 6.9 and later, Role Right-Sizer registers [abilities](https://developer.wordpress.org/apis/abilities/), which AI agents find and run through the [MCP adapter](https://github.com/WordPress/mcp-adapter) and anything else through the REST API under `/wp-abilities/v1/`. Each one is marked public, which the MCP adapter needs before it lists an ability. All of them need the Role Right-Sizer capability, and the same protections apply as on the screen. Abilities that are about one account also check that you may edit (or, to change a role, promote) that account.

| Ability | Does |
| --- | --- |
| `role-right-sizer/get-recommendations` | Read-only. `learning`, `learning_ends`, `built`, the user recommendations (`login`, `type`, `current`, `proposed`, `lost`, `last_active`) and the unused capabilities on roles (`role`, `cap`, `users`, `owner`, `owner_active`) |
| `role-right-sizer/get-user-usage` | Read-only. For `user` (ID, username or email): every capability and ability with first and last use and whether it is in the window, last login and activity, and the recommendation |
| `role-right-sizer/apply-recommendation` | Destructive, so the REST API runs it with `POST`. Applies the recommendation for `user_id`, or removes `capability` from `role`. Returns the logged change. Refused (400) for your own account, the last administrator and the exclude list |
| `role-right-sizer/undo-change` | Destructive. Undoes `change_id`, or its whole batch with `batch: true` |
| `role-right-sizer/get-changes` | Read-only. The change log, newest first, up to `limit`. Changes to accounts you may not edit are left out |
| `role-right-sizer/list-trials` | Read-only. Trials, optionally only `open` (active or over and waiting), `active`, `ended`, `kept` or `restored`: who, days left, the roles, whether they asked for access back, and every denial with its count |
| `role-right-sizer/start-trial` | Destructive. Gives `user_id` their recommended smaller role for `days` (default 14) and returns the trial. Refused (400) for your own account, the last administrator, the exclude list and accounts that are not in use |
| `role-right-sizer/restore-trial` | Destructive. Gives the person their old role back and closes the trial |
| `role-right-sizer/extend-trial` | Adds `days` to a trial, counted from now when its time is up |
| `role-right-sizer/keep-trial` | Makes the smaller role permanent. The change stays in the log. Refused (400) for a trial that is over. Agents cannot decide a trial any other way |

When [Agent Governance](https://github.com/aryanjasala/agent-governance) holds an agent's call to `apply-recommendation`, `undo-change`, `start-trial` or `restore-trial`, Role Right-Sizer fills in its preview through `agent_governance_dry_run`: who moves from which role to which, the capabilities lost, or what an undo puts back. Nothing changes while the preview is built.

## WP-CLI

```sh
wp role-right-sizer report [--all-users] [--cached] [--format=table|csv|json|yaml|count]
wp role-right-sizer usage <user> [--format=...]
wp role-right-sizer apply <user>... [--dry-run]
wp role-right-sizer apply --all [--include-plugin-caps] [--dry-run] [--yes]
wp role-right-sizer changes [--format=...]
wp role-right-sizer undo <change-id> [--batch]
wp role-right-sizer forget <user>
wp role-right-sizer settings [--dormant-action=remove|fallback] [--fallback-role=<role>] [--trial-end-action=ask|keep]
wp role-right-sizer trial start <user>... [--days=<days>]
wp role-right-sizer trial list [--status=open|active|ended|kept|restored] [--format=...]
wp role-right-sizer trial denials <trial-id> [--format=...]
wp role-right-sizer trial restore <trial-id>
wp role-right-sizer trial extend <trial-id> [--days=<days>]
wp role-right-sizer trial keep <trial-id>
```

`report` builds the recommendations first unless `--cached` is given. `--all-users` lists every user in the columns of the CSV download. `report` lists unused capabilities with their `owner` and `owner_active`. `apply --all` applies every user and role recommendation as one batch, except capabilities that belong to an active plugin, post type or taxonomy; it names those and leaves them alone unless you add `--include-plugin-caps`. Users can be IDs, usernames or email addresses. WP-CLI runs without a logged-in user unless you pass `--user`, so the "not your own account" rule only applies with `--user`. The last administrator rule always applies.

`settings` shows the settings, or changes the ones you pass and rebuilds the recommendations. They live in the `role_right_sizer_settings` option. The excluded users and roles are changed on the Settings tab or with `wp option`.

## PHP API

```php
$plugin = AryanX\Axle\RoleRightSizer\Plugin::instance();

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
| `role_right_sizer_trials` option | The trials with their denials, newest first. Closed trials go after a year. Not autoloaded |
| `{prefix}role_right_sizer_trial` user option | The ID of the open trial of a person, so only they are watched. Removed when the trial closes |
| `role_right_sizer_steps_dismissed` user meta | Set when someone dismisses the Get started card |
| `role_right_sizer_capability_history` option | When each role gained each capability, and the plugin folder that added it when known: `role => [ cap => [ first_seen, folder ] ]`. Updated when the roles change and on every report build. Not autoloaded |

`{prefix}` is the site's table prefix, such as `wp_` or `wp_2_`. The cron events are `role_right_sizer_daily`, `role_right_sizer_build` and, while a trial is open, `role_right_sizer_trial_check` (hourly). Deactivating stops them. Uninstalling removes all of the above on every site of a network. Roles and capabilities stay as they are: applied changes are not undone.

## Privacy

The usage record, the change log and the trials are personal data: when someone was active, what they did, and what they could not do during a trial. They take part in **Tools > Export Personal Data** and **Tools > Erase Personal Data**. Erasing deletes the usage record, the changes to that person's account and their trials, and keeps changes and trials they started without their name. Closed trials are removed after a year (`role_right_sizer_trial_retention_days`). **Settings > Privacy** gets suggested text.

## Multisite

Roles are per site, so usage, recommendations and the change log are too. Super admins get no recommendations, because they can do everything on every site. **Network Admin > Users > Right-Sizer** lists each super admin with their last login and, per site, their last activity and how many capabilities they used.

## Limits

- Hooks only see what WordPress or a plugin does through them. A plugin screen whose saves fire no mapped hook and go through `admin-post.php` or admin-ajax is counted only by the screen visit. Add its hook to the map.
- A capability used less often than the window (a yearly export) looks unused. Widen the window or exclude the account.
- Capability checks in REST callbacks after the permission check are not recorded, on purpose. A route that checks permissions inside its callback instead counts only through the mapped hooks.
