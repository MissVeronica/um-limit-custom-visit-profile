# UM Limit Custom Visit Profile
Extension to Ultimate Member to limit the subscribed user to certain amount of profile views.
## Settings
UM Settings -> Access -> Other

User status display: User Account Page tab

## Creation of User Roles
1. UM User Roles -> Add New -> Enter "Gold" will create the UM user role ID: um_gold
2. UM User Roles -> Add New -> Enter "Gold unpaid" will create the UM user role ID: um_gold-unpaid
3. Use the “User Role Editor” plugin if you want another  display name for "Gold unpaid" like "Waiting for gold payment"
4. Name all the highest role levels accordingly for a safe realation between paid and unpaid roles.
5. Use in this case "-unpaid" as the suffix in UM Settings -> Access -> Other -> "Limit Profile Visits - Downgrade Role Suffix"
## WooCommerce settings
1. Add to products for purchasing profile views/visits an attribute um_view_profile_limit with number of visits
2. How to change user role after subscription https://docs.ultimatemember.com/article/1493-how-to-change-user-role-after-subscription

## Customization of Members Directory
Make local copies of the template files members-list.php and members-grid.php by reading this guide: 

https://docs.ultimatemember.com/article/1516-templates-map

Edit the local template files for the UM Members Directory and add the code for the "Warning red icon" <code><div>{{{user.custom_visited_warning}}}</div></code>

1. members-list.php: add after the "/a>" on line 34 or at any other template location
2. members-grid.php: add after the "/a>" on line 44 or at any other template location
## Translations or Text changes
Use the "Say What?" plugin with text domain ultimate-member

## Updates
Version 1.0.0 from Beta
1. Two additional columns in WP All Users: Views, Limit
2. Roles setting: Paid roles, Downgrade roles with common suffix
3. Paid roles are redirected to Limit Visit Profile when limit reached
4. Downgraded Role redirect to Profile Page
5. Display Account tab when there are visits with orders/views
6. Plugin first activation: DB table "wp_custom_visited_profiles" created

Version 1.1.0
1. New icon 'um-faicon-users' Account page
2. Date/Time hover "Time ago"

Version 1.2.0
1. Order item quantity updates
2. Improved field formatting

Version 1.3.0
1. Fixed "Time ago" error

Version 1.4.0
1. Downgraded Role Redirect page setting

Version 1.5.0
1. Warning red icon for not paid profiles

Version 1.5.1
1. Text updates
2. Red warning icon blinking

## Installation
Download the zip file and install as a WP Plugin, activate the plugin.
