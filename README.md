modified fork of ajshort/silverstripe-gridfieldextensions

SilverStripe Grid Field Extensions Module
=========================================

This module provides a number of useful grid field components:

* `GridFieldAddExistingSearchButton` - a more advanced search form for adding
  items.
* `GridFieldAddNewInlineButton` - builds on `GridFieldEditableColumns` to allow
  inline creation of records.
* `GridFieldAddNewMultiClass` - lets the user select from a list of classes to
  create a new record from.
* `GridFieldEditableColumns` - allows inline editing of records.
* `GridFieldOrderableRows` - drag and drop re-ordering of rows.
* `GridFieldRequestHandler` - a basic utility class which can be used to build
  custom grid field detail views including tabs, breadcrumbs and other CMS
  features.
* `GridFieldTitleHeader` - a simple header which displays column titles.

See [docs/en/index.md](docs/en/index.md) for documentation and examples.

## Requirements

Silverstripe 3.7.x

### Notice
This repository uses the git flow paradigm.
After each release cycle, do not forget to push tags, master and develop to the remote origin
```
git push --tags
git push origin develop
git push origin master
```
