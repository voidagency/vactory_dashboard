# Changelog

All notable changes to this module will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),

---

## [1.0.0] - 2025-06-23 / 2025-06-26
### Added
- Added Preview button functionality.
- Enabled WYSIWYG support for long text fields.
- Supported checkbox input for dynamic fields.
- Implemented mobile version of the interface.
- Added custom 404 error page.
- Introduced FAQ field support.
- Supported file and remote video inputs for dynamic fields.
- Added button to preview Metatags.
- Added new permission: `Accéder au mode avancé`.
- Implemented node auto-save feature.

### Changed
- Improved layout and styling of the extended URL input field.
- Assigned default permissions (dashboard access, sitemap, media, etc.) to the `webmaster` role during `drush updb`.
- Enhanced sidebar performance by optimizing data loading.
- Fixed image input handling for dynamic fields.
- Resolved publishing and unpublishing issues for nodes.
- Optimized Redmine integration and configuration.
- Improved user listing display and filtering.
- Enhanced media add form with a loading state indicator.
- Replaced raw HTML tags in sidebar items with cleaner markup.
- Fixed errors related to content moderation states.
- Refactored module structure for better maintainability.
- Added a language column to the content types listing.
- Made the block form header sticky for better UX.
- Updated permission handling:
    - Now using `administer dashboard configuration`.
    - Removed deprecated permissions.  
      ([See commit](https://bitbucket.org/adminvoid/vactory8/commits/0c6566d91c1d3f23b79a0b7bacc05623f046f723))
---

