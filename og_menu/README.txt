
DESCRIPTION
-----------
Enable users to manage menus inside Organic Groups.

REQUIREMENTS
------------
- Organic Groups module (http://drupal.org/project/og).
- Menu module.

INSTALLATION
------------
- Enable the module.
- Give "administer og menu" permission to the desired roles.
- Visit the og menu configuration page to configure at will.

USAGE
-----
- Administrators can create OG menus through the regular menu interface at admin/structure/menu/add. Choose a group to associate with the menu.
- Organic group members with the right permission can also manage menus at node/[nid]/og_menu.
- For content types that can be published in groups, users can add a menu link directly from the node creation form.
- For groups content types, users can create an associated menu by checking "Enable menu for this group".
- You can enable the "OG Menu : single" and the "OG Menu : multiple" blocks at admin/build/block.
  - OG Menu : single will display the first available menu for the first available group in the context.
  - OG Menu : multiple will display all available menus for all available groups in the context.
- OG menus won't show on the regular menu interface. They show up on admin/structure/og_menu.
- Ability to hide OG Menu's from the block admin interface.


NOTES
-----
Be aware that since menu administration forms are mostly duplicated, if a contrib module adds functionality to menu administration
forms without additional permissions, these additions may be available for OG menu users with 'administer og menu' permission.
This could allow these users to be able to do things you don't want them to. Please report these modules if you catch one.

TODO/BUGS/FEATURE REQUESTS
--------------------------
- See http://drupal.org/project/issues/og_menu. Please search before filing issues in order to prevent duplicates.
- Please test the D7 release and report any bugs or suggestions you might find.

UPGRADING FROM 6.x TO 7.x
-----------------------------
- There currently is no upgrade path! If you need an upgrade path, please file an issue.

CREDITS
-------
Originaly authored and maintained by Scott Ash (ashsc).
New maintainer for 6.2.x version : Julien De Luca (jide).

7.x port contributors:
  - Stefan Vaduva (http://vamist.ro)
  - Nick Santamaria (http://www.itomic.com.au)
  - Frederik Grunta (Aeternum)
  - Wim Vanheste (rv0) (http://www.coworks.net)

7.x maintainers
  - Frederik Grunta (Aeternum)
  - Wim Vanheste (rv0) (http://www.coworks.net)