# Content Edit Plugin

Front end editing of the **md** contents of web pages.

The **Content Edit** Plugin is for [Grav CMS](http://github.com/getgrav/grav). The plugin is designed for corporate websites where PR & marketing users want to update a site without needing much technical training (a working knowledge of **md** is required, but is relatively simple). In addition, it is required that the users should NOT have any admin or supervisor permissions on the site. It is required only that they update existing **md** pages. Furthermore, it is required that different users can be given access to different pages.

## Installation

Installing the Content Edit plugin can be done in one of two ways. The GPM (Grav Package Manager) installation method enables you to quickly and easily install the plugin with a simple terminal command, while the manual method enables you to do so via a zip file.

### GPM Installation (Preferred)

The simplest way to install this plugin is via the [Grav Package Manager (GPM)](http://learn.getgrav.org/advanced/grav-gpm) through your system's terminal (also called the command line).  From the root of your Grav install type:

    bin/gpm install content-edit

This will install the Content Edit plugin into your `/user/plugins` directory within Grav. Its files can be found under `/your/site/grav/user/plugins/content-edit`.

### Manual Installation

To install this plugin, just download the zip version of this repository and unzip it under `/your/site/grav/user/plugins`. Then, rename the folder to `content-edit`. You can find these files on [GitHub](https://github.com/finanalyst/grav-plugin-content-edit) or via [GetGrav.org](http://getgrav.org/downloads/plugins#extras).

You should now have all the plugin files under `/your/site/grav/user/plugins/content-edit`

> NOTE: This plugin is a modular component for Grav which requires [Grav](http://github.com/getgrav/grav) and the [Error](https://github.com/getgrav/grav-plugin-error) and [Problems](https://github.com/getgrav/grav-plugin-problems) to operate, and [Login](https://github.com/getgrav/grav-plugin-login).

### Admin Plugin

If you use the admin plugin, you can install directly through the admin plugin by browsing the `Plugins` tab and clicking on the `Add` button.

## Configuration

Before configuring this plugin, you should copy the `user/plugins/content-edit/content-edit.yaml` to `user/config/plugins/content-edit.yaml` and only edit that copy.

Here is the default configuration and an explanation of available options:

```yaml
enabled: true
iframePreview: true
divPreview: false
```

- `iframePreview` turns on a review window in an iframe. It seems that some server/browser combinations generate an error when embedding a page in an iframe (due to a hacking vulnerability).
- `divPreview` turns on review window in a div. Currently this is only an approximation of the html (maybe a better way will be discovered).
- Some people find a preview window on the same page as the **md** editor to be distracting. All on-page review is turned off by disabling both `iframePreview` and `divPreview`.

Note that if you use the admin plugin, a file with your configuration, and named content-edit.yaml will be saved in the `user/config/plugins/` folder once the configuration is saved in the admin.

## Usage

A special page is created to be rendered by the `content-edit` template. Within the header of the page a **page collection** is created. The contents of this collection, or more precisely, the `routes` to files with `.md` content,  is then displayed as a tree with a button for editing, and - if the route will generate html that can be reviewed - a label indicating whether the page can be reviewed on-page and an anchor to open the page in another browser tab.

### Security

Security is handled by the **Login** plugin. The page rendered with the `content-edit` template to provide editing access **SHOULD** be protected by only granting access to users with specific permission. More about permissions can be found in the **Login** documentation.

Allowing for any frontend editing of content creates a vulnerability for hacking. This vulnerability is controlled through the **Login** plugin and setting access permissions on pages that expose content for editing.

A website developer should be aware that since arbitrary JS scripts can be included in **md** content, care must be taken to give editing permission to users.

### Example

For example, suppose we create a `group` called **editors** and within `user/config/groups.yaml` we have:
```yaml
editors:
      groupname: 'editors'
      readableName: 'PR Staff'
      description: 'For managing corporate site'
      icon: 'pen-fancy'
      access:
          site:
              login: 'true'
              edit: 'true'
```

Next, we create the file `user/pages/site_editing/content-edit.md` (remember that if the directory is not prefixed with a number, as per GRAV defaults, then it will not be visible in the menu, but can be navigated to in the browser URL bar):
```yaml
---
title: Site edit
content:
    items: '@root'
access:
    site: edit
---
```

Now any user that belongs to the `editors` group will be able to access `http://somecorporate.com/site_editing` (assuming the GRAV starts in the www root).

For example, if we create a user called **peter** and under `user/accounts/peter.yaml`:

```yaml
email: peter@somecorporate.com
fullname: Peter Piper
title: tester
language: en
groups:
  - editors
hashed_password: qwertyuiop
```

The key item, of course, is groups.

## Editor use

The `content-edit` template will generate a page with three (by default) sections:
- a tree of pages and subpages. Each page is normally (see below for granular control) accompanied by an **Edit** button and a **Goto** link, and optionally a **Preview** label.
- a section containing an **md** editor. When the **Edit** button associated with a **route** is clicked, the `*.md` content of the associated page is transfered to the Editor section, and can be edited. The edited **md** content can then be transferred back to the file for that page (see below).
- a section with the Preview section (if either one or both of `iframePreview` or `divPreview` are enabled, otherwise this section does not appear).

Above the editor section (generated by the simplemde Jquery plugin), there are two buttons **SAVE** and **PREVIEW**. When **SAVE** is clicked, the edited **md** content is transferred back to the `*.md` file associated with the `route`, and an entry is made in the `content-edit.log` file giving the date, the user name, and the route edited.

If a route can be previewed, then the **PREVIEW** button will be enabled and clicking on the **PREVIEW** button will cause **GRAV** to generate the html for the route and transfer it to Review section. If neither Preview option is enabled, the review button will not be visible.

### Page Collections and Different User Groups

All of the files in the `page.collection` will be listed. More about collections can be found in [Grav documentation](https://learn.getgrav.org/content/collections).

If different groups of users are to be given access to different pages, then each page can be assigned some tag appropriate to the user group, and on the content-edit page for that group, the page collection can be created using the appropriate tag.

Currently, there has to be a separate content-edit page for each separate page collection because access is granted on a per page basis, not on a per collection basis.

### More Granular Control

There are two options that are provided on a per page basis. The following may appear in the header of a standard page:
```yaml
contentEdit:
    noPreview: true
    noEdit: true
```

Even though it is possible to fine-tune a page collection, there may be pages falling within the collection that should only be edited by a staff member with supervisor permissions.

On each page that should not be edited, the option `contentEdit.noEdit` should be enabled (see above example).

When a web page is created dynamically from a number of `*.md` sources, then GRAV will probably generate an error if the browser is pointed to a sub-page. This is true for [modular pages](https://learn.getgrav.org/content/modular) and for pages within a [sequential-form](https://github.com/finanalyst/grav-plugin-sequential-form).

The `content-edit` plugin can detected a modular page, but cannot detect other forms of dynamic pages. Consequently, the website developer needs to manually label such pages with `contentEdit.noPreview: true` in the page header.

However, each of separate page within a modular page may contain *md* content that should be available for frontend editing. An *Edit* button will be generated for each page in a modular page (provided that `contentEdit.noEdit: true` is not present in that page's header).

All previews can be turned off by disabling `iframePreview` and `divPreview` in the plugin configuration file.

## Credits

1. GRAV and LOGIN, of course.
1. Credit is due to [SimpleMDE](https://http://simplemde.com) for its embeddable *md* editor.
1. A great deal of this plugin is inspired by, and chunks just copied from, the  [editable-simplemde](https://github.com/bleutzinn/grav-plugin-editable-simplemde) plugin for GRAV. A very innovative plugin!
1. The [php-diff library](https://github.com/chrisboulton/php-diff) by Chris Boulton.

## To Do

- [ ] Better review of html that can be embedded into a div tag.
- [ ] Provide a mechanism to sanitise tags that could generate active content in an *md* file.
