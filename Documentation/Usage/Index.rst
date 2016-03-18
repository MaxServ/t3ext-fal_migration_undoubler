.. ==================================================
.. FOR YOUR INFORMATION
.. --------------------------------------------------
.. -*- coding: utf-8 -*- with BOM.

.. include:: ../Includes.txt



.. _usage:

=====
Usage
=====

Ensure the _cli_lowlevel user has the proper permissions
========================================================

The user running the command-line task must have persmissions to remove files from the `_migrated` folder. You will need to edit the `_cli_lowlevel` user in the backend and grant him/her access to a filemount containing the `_migrated` folder. If you don't do this, the files can not be deleted and the migration will exit.

.. figure:: ../Images/Edit_filemount.png
   :alt: Create a filemount with access to the _migrated folder

   Create a filemount with access to the _migrated folder

.. figure:: ../Images/Edit_cli_lowlevel.png
   :alt: Attach the filemount to the _cli_lowlevel user

   Attach the filemount to the _cli_lowlevel user

Command Reference
=================

First do a dry-run to see what will be re-referenced.

.. code-block:: bash

    php ./typo3/cli_dispatch.phpsh extbase undouble:migratedfiles --dry-run

If that output looks good:

.. code-block:: bash

   php ./typo3/cli_dispatch.phpsh extbase undouble:migratedfiles

.. note::
  This reference uses ``./typo3/cli_dispatch.php extbase`` as the command to
  invoke.

The commands in this reference are shown with their full command identifiers.
On your system you can use shorter identifiers, whose availability depends
on the commands available in total (to avoid overlap the shortest possible
identifier is determined during runtime).

To see the shortest possible identifiers on your system as well as further
commands that may be available, use::

  ./typo3/cli_dispatch.php extbase help

.. note::
  Some commands accept parameters. See ``./typo3/cli_dispatch.phpsh extbase help <command identifier>`` for more information about a specific command.

The following reference was automatically generated from code on 15-03-16

.. contents:: Available Commands
  :local:
  :depth: 1
  :backlinks: top

undouble:updatetypolinkfields
*****************************

**Update typolink enabled fields**

Finds rich text fields with file references. If these links point to migratable files, they will be updated. The
TCA is inspected and all fields with a softref confiugration of type `typolink` will be processed. You can
also specify a table and field to process just that field.

Options
^^^^^^^

``--table``
  The table to work on. Default: ``.
``--field``
  The field to work on. Default: ``.
``--dry-run``
  Do a test run, no modifications.

undouble:updatetypolinktagfields
********************************

**Update typolink_tag enabled fields**

Finds rich text fields with file references. If these links point to migratable files, they will be updated. The
TCA is inspected and all fields with a softref configuration of type `typolink_tag` will be processed. You can
also specify a table and field to process just that field.

Options
^^^^^^^

``--table``
  The table to work on. Default: ``.
``--field``
  The field to work on. Default: ``.
``--dry-run``
  Do a test run, no modifications.

undouble:migratedfiles
**********************

**Normalize _migrated folder**

De-duplication of files in _migrated folder. Relations to files in migrated folder will be re-linked to an
identical file outside of the _migrated folder.

Options
^^^^^^^

``--dry-run``
  Do a test run, no modifications.

undouble:removemigratedfiles
****************************

**Remove files from _migrated folder**

Removes files with counterparts outside of the _migrated folder and without references
in sys_file_reference from the _migrated folder.

Options
^^^^^^^

``--dry-run``
  Do a test run, no modifications.
``--i-know-what-im-doing``
  Do you know what you are doing? If provided, will remove the files. Can be used together with --dry-run to get a preview of the files to be removed.
