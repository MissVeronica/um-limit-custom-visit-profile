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
## Updates
This is a Beta version under development and tests
### 0.1.0 Beta 
1. Two additional columns in WP All Users: Views, Limit
2. Roles setting: Paid role, Downgrade role
3. Only Paid and Downgrade roles are included in the Limit Visit Profile
4. Addition of linked profile photos on Account Page.
5. Downgrade the user role at redirect to limit page
### 0.2.0 Beta 
1. Second Paid User Role
2. Hover 120px*120px Profile Photo
### 0.3.0 Beta
1. Paid User Role multiselect
### 0.4.0 Beta
1. Additional option with Suffix for Paid Role ID to Downgrade Role ID
### 0.4.5 Beta
1. Bug fix 
### 0.5.0 Beta
1. Bug fix with -unpaid
2. User role name added to Account page
### 0.6.0 Beta
1. Remove current role if downgrading role exists
### 0.7.0 Beta
1. Display Account tab when there are visits
## Installation
Download the zip file and install as a WP Plugin, activate the plugin.
