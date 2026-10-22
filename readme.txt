=== Role Right-Sizer ===
Contributors: aryanjasala
Tags: roles, capabilities, user roles, security, permissions
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Recommends the smallest role each user needs, based on what they actually do. Spots dormant accounts and unused capabilities, with one-click undo.

== Description ==

Sites collect administrators who only ever write posts, old agency accounts nobody uses, and capabilities a plugin added years ago. Role editors let you change roles, but they don't tell you what to change.

Role Right-Sizer records which capabilities each person actually uses, then recommends the smallest role that covers it:

* **Smaller role**: an administrator who only writes, edits and moderates gets "Editor", with the capabilities they would lose and when they last used each one.
* **Dormant accounts**: no activity for 90 days. Take every role away on this site, or move them to a fallback role you pick.
* **Never logged in**: accounts with no login since tracking began, listed on their own.
* **Unused capabilities on roles**: capabilities a role grants that none of its users used, usually left behind by a plugin. Remove them from the role.

Nothing changes until you apply a recommendation. You see every capability a change takes away before you confirm it, and every change goes into a log with an **Undo** button, for one change or a whole batch.

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

= How usage is recorded =

* Saving, publishing and deleting posts, managing plugins, themes, users, terms, comments, menus and the Customizer, imports, exports and settings screens: each event counts as use of the exact capability WordPress checked for it.
* Opening an admin screen counts as use of the capability its menu entry needs.
* REST API requests that change something count the capabilities their permission check needed. Abilities record their name.
* Only capabilities the person really has are recorded.

It is cheap enough for busy sites: everything is collected in memory and written once at the end of the request, and the same capability is written at most once an hour per person.

= Safe by default =

* Recommendations stay hidden for the first 30 days, while it learns. The screen shows how long is left.
* A recommendation never gives more access: the proposed role only has capabilities the person already has, and keeps `read`.
* The last administrator always keeps the role, and you can never change your own role here.
* Exclude people or whole roles, such as a break-glass admin account.
* Changes go through core's own role functions, so other plugins see them.

= For developers and AI agents =

* Filters for every default, limit and list, and actions for every change and undo. See the reference on GitHub.
* WP-CLI: `wp role-right-sizer report`, `usage`, `apply`, `undo`, `changes` and `forget`.
* On WordPress 6.9 and later, abilities let AI agents read the recommendations, apply one and undo it, with the same protections.
* A CSV download of every user with their role, recommended role, last activity and the capabilities they used.
* A Site Health check that says when accounts have more access than they use.

= Privacy =

The activity record is personal data: when someone was last active and which capabilities they used. It takes part in Tools > Export Personal Data and Erase Personal Data, Settings > Privacy gets suggested text, and uninstalling deletes all of it.

== Frequently Asked Questions ==

= Why does it wait 30 days? =

A role you only need once a month, such as running an export, would look unused after a week. Change the period with the `role_right_sizer_learning_days` filter.

= What about capabilities granted straight to a user, not through a role? =

They are left alone. Recommendations only change roles.

= Does it work on multisite? =

Yes. Roles are per site, so usage and recommendations are too. Super admins can do everything everywhere, so they get no recommendations; Network Admin > Users > Right-Sizer shows what each super admin did, per site.

= Can I undo a change much later? =

Yes, as long as it is in the log, which keeps the last 500 changes. Undo puts back the exact roles or capability the change took away.

== Screenshots ==

1. Users > Right-Sizer: a smaller role, dormant accounts and unused capabilities on roles.
2. The confirmation step lists every capability each change takes away.
3. The change log, with Undo for one change or the whole batch.
4. The learning period, with how long is left before recommendations show up.

== Changelog ==

= 1.0.0 =
* First release.
