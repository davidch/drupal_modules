7.x-1.x RELEASE NOTE:
 (Jan 17 2012) The current 7.x branch is under development and is still unstable but core features are working.


The Filedepot Document Management module satisfies the need for a full featured document management module supporting role or user based security.
 - Documents are saved to a directory under the Drupal Private file system to protect corporate documents for safe access and distribution.
 - Flexible permission model allows you to delegate folder administration to other users.
 - Define view, download and upload access for selected users, roles or groups (If OG module installed) - any combination of permissions can be setup per folder.
 - Organic Group support and options to auto-create a new top level folder for each new group.
   If you access the main filedepot link with the group id or group name passed in as an argument,
   the user will only see the folders and files under the OG created Top Level folder
 - Cloud Tag and File tagging support to organize and search your files.
   Document tagging allows users to search the repository by popular tags.
   Users can easily see what tags have been used and select one of multiple tags to display a filtered view of files in the document repository.
 - Users can upload new versions of files and the newer file will automatically be versioned. Previous versions are still available.
 - Users can selectively receive notification of new files being added or changed.
   Subscribe to individual file updates or complete folders. File owner can use the Broadcast feature to send out a personalized notification.
 - Convenient reports to view latest files, most recent folders, bookmarked files, locked or un-read files.
 - Users can flag document as 'locked' to alert users that it is being updated.
 - File Downloads are logged to the Druapl Recent Log messages
 - Check the Drupal Recent Log messages for any module errors and other module events - type: filedepot

The Filedepot module is provided by Nextide www.nextide.ca and written by Blaine Lang (blainelang)


Dependencies
------------
 * Ctools
 * libraries

 Organic Groups is not required but if you install the modules 'og' and 'og_access',
 then you will be able to manage folder access via organic groups.
 The module will automatically detect if both modules are enabled and will only
 show the group options for permissions once both 'og' and 'og_access' modules are enabled.
 Review the module configuration settings once OG is enabled to enable OG Mode settings

Requirements
------------
 PHP 5.2+ and PHP JSON library enabled.

 As of PHP 5.2.0, the JSON extension is bundled and compiled into PHP by default.


Install
-------

1) Copy the filedepot folder to the modules folder in your installation.

2) The filedepot module now requires the libraries module be installed.
   We are not permitted to include NON-GPL or mixed license files in the module distribution as per Drupal guidelines.

   You will now need to create a sites/all/libraries folder if you don't already have the libraries module installed.
   PLEASE rename the files as noted below

   The following three javascript and support files then need to be retrieved and saved to the sites/all/libraries folder.
   > http://www.strictly-software.com/scripts/downloads/encoder.js  - SAVE FILE as: html_encoder.js
   > http://jquery.malsup.com/block/#download  - SAVE FILE as jquery.blockui.js

3) Check that your site has the Private file system path setup. Filedepot uses the private file system for it fiile repository is is required so that filedepot can

4) Enable the module using admin/modules
   The module will create a new content type called 'filedepot_folder'

5) Review the module settings using admin/settings/filedepot
   Save your settings and at a minium, reset to defaults and save settings.

6) Access the module and run create some initial folders and upload files
   {siteurl}/index.php?q=filedepot    (/fildepot - with clean-urls on)

7) Review the permissions assigned to your site roles: {siteurl}/index.php?q=admin/user/permissions
   Users will need atleast 'access filedepot' permission to access filedepot and to view/download files.

Notes:
a)  You can also create new folders and upload files (attachments) via the native Drupal Content Add/View/Edit interface.
b)  A new content type is automatically created 'filedepot_folder'.
c)  You can setup filedepot to not load the YUI libraries remotely from Yahoo via the module admin settings page.
    Set the baseurl to be a local URL and download the YUI libraries from http://yuilibrary.com/downloads/#yui2
    Only version 2.7.0 of the libraries is supported at present.

    You can also load the YUI libraries from Google:
    http://ajax.googleapis.com/ajax/libs/yui/2.7.0/build/

    Only google supports loading them using a https URL
    https://ajax.googleapis.com/ajax/libs/yui/2.7.0/build/

