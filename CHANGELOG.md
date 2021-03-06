# v1.3.0
## 12 August 2018
1. [](#enhancement)
    * Add file/image delete button on file editor
    * Content of `content-edit.md` page is used as placeholder text before md content moved.
    * Fix bug when menu not set in page header_navigation

# v1.2.1
## 8 August 2018
1. [](#bugfix)
    * Add language data to load file/image (not used, but option needs to be set)

# v1.2.0
## 30 July 2018
1. [](#enhancement)
    * Inclusion of functionality to handle multi-language sites.
    * removed all but 'saving' message, which now appears over Save button
    * placed save & review buttons on right of editor window.
    * Included possibility to edit the 'menu' item of the page header
    * When multiple language files are available for a page, and the site configuration is set for multiple languages,
        * The languages pages present and are supported are included in the tree.
        * The language of the page is included in the editor header
    * A page header option`dontInclude` is provided to remove a route from the page.collection.
    * Various style sheet changes.
    * Text in code and in Twig files moved to `languages.yaml`.

# v1.1.0
## 24 July 2018

1. [](#enhancement)
    * Removed iframePreview and divPreview
    * Combined into single option `preview`
    * Created new template `content-edit-review` to allow for frontend visualisation of edits by month
    * iframe container for Preview can be used for all servers, no X-Frame errors.

# v1.0.0
##  22 July 2018

1. [](#new)
    * Plugin ready for publication
