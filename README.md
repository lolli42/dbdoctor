![tests core v11](https://github.com/lolli42/dbdoctor/actions/workflows/testscorev11.yml/badge.svg)
![tests core v12](https://github.com/lolli42/dbdoctor/actions/workflows/testscorev12.yml/badge.svg)

TYPO3 DB doctor
===============

# Mission

The mission of this extension is to find database inconsistencies that may
have been introduced in a living TYPO3 instance over time, and to fix them.

As example, when a page tree is deleted by an editor, it sometimes happens
that most pages are properly set to deleted, but some pages are missed, or a
content element on one page is not deleted. This leads to orphan pages or
content elements in the database.

There can be many reasons to end up with invalid database state like the above:
TYPO3 in general has no referential integrity constrains on database tables,
inconsistencies can be triggered by a dying PHP process, a lost DB connection, a
core bug, a buggy extension, a broken deployment, and more. Long living active
instances that were upgraded through multiple major core versions tend to end up
with something that isn't quite right anymore.

Such inconsistencies can lead to further issues. For instance if a page
is copied that has an orphaned localized record, the system tends to mess up
localizations of the copied page, too. Editors then stumble and TYPO3 agencies
have to do time-consuming debugging sessions to find out what went wrong.

This extension provides a CLI command that tries to find various such inconsistencies
and gives admins options to fix them.


# Alternatives

We're not aware of other open extensions that try to achieve the same in a similar
systematic way. The core `lowlevel` extension comes with a couple of commands that
try to clean up various DB state, but their codebase is rather rotten and hard to
maintain.

This extension is not a substitution of `lowlevel` commands (yet?), it's more an
incubator to see if a certain strategy dealing with inconsistencies actually works
out in projects. It will grow over time. Maybe it ends up in the core, or the core
refers to this extension as "maintenance" extensions in the future. We'll see.


# Strategy

The strategy of this command is to check for single things one-at-a-time and to
fix them before going to the next check. Updates and deletes of not-ok records
are done with low-level database queries directly, not using the DataHandler.

Single checks are carefully crafted and functional tested and the order in which
they are executed is important. It can happen that a single check is run multiple
times in the chain.

Single checks rather try to avoid memory consumption and assumed state at the cost
of more queries being executed. Queries are often performed as prepared statements
to re-use them often in a single check. Statements are properly closed when a single
check finished, effectively using the PHP garbage collection. All-in-all, this command
should be  relatively quick even for big-sized instances, but it will hammer the
database a lot.


# Current status

First releases have been done, but we're not confident enough to have a 1.0.0, yet.
The nature of this extension is to perform potential malicious queries, so use the
system with care. We are however using this extension for some of our customers with
success already.


# Installation

## Composer

The extension currently supports TYPO3 v11 and TYPO3 v12. The extension can be installed
as non-dev dependency (not adding `--dev` to `composer require`): It has no impact on a
live instance (except dependency injection definitions) as long as it is not actively
executed via CLI.

```
$ composer require lolli/dbdoctor
```

## TYPO3 Extension Repository

For non-composer projects, the extension is available in TER as extension key
`dbdoctor` and can be installed using the extension manager.


# Preparation

The nature of the CLI command is to perform destructive database operations on your
instance. As such, a few things should be kept in mind:

* [!!!] 💣 **Create a fresh database backup dump before and after using the CLI interface.**
  Ensure the recovery strategy actually works: Both the extension and the user can potentially
  get something wrong. We are dealing with low level database stuff here after all,
  so things can potentially go south rather quickly. See the "Further hints" section below, too.

* [!!!] 💣 Make sure the TYPO3 "Database analyzer" is happy and needs no new or changed columns or tables.
  An early check verifies missing tables and columns, but it is still a good idea to double-check
  before running dbdoctor.

* [!!!] There should not be any pending core upgrade wizards. dbdoctor currently does not check
  up-front if all upgrade wizards have been executed.

* [!!!] Run the reference index updater when this command finished! It is very likely
  it will update something. A clean reference index becomes more and more important
  with younger core versions. The CLI command to do this: `bin/typo3 referenceindex:update`.


# Usage

```
$ bin/typo3 dbdoctor:health
```

The interface looks like this:

![](Documentation/cli-example.png)

Note the above image is notoriously outdated, the interface of the current version
may look slightly different. We're too lazy to update the image often, but it should
give a solid idea on how the interface looks like.

The main command is a chain of single checks. They are done one by one. Affected
record details can be shown on a per-page and a per-record basis to give a quick
overview. The interface allows deleting or updating of affected records, depending
on the type of the check.

The default interactive mode will never perform updates automatically and
always asks the user for actions. When pressing 's' (simulate/show), the queries
that *would* be performed are shown, when pressing 'e' (execute), the queries
are actually executed.

In the example above, eight pages are found that have no connection to the
tree-root anymore. A help is selected, then an overview to show more record
details. Finally, the records are deleted and the next check is called. The
delete queries are shown, which can become handy if those should be executed
manually on a different server.


# Options

The CLI command can be executed with a couple of options. The default mode is "interactive",
prompting for user input after each failed check.

* Help overview:
  ```
  $ bin/typo3 dbdoctor:health -h
  ```
  Left to the reader to find out what is done here :-P

* Interactive mode: `--mode interactive` or `-m interactive` or option not given:
  ```
  $ bin/typo3 dbdoctor:health -m interactive
  ```
  The default mode: Perform checks one-by-one and have an interactive interface
  to see affected record details, show pages with affected records, simulate
  performed queries, reload check and ultimately execute queries.

* Check mode: `--mode check` or `-m check`:
  ```
  $ bin/typo3 dbdoctor:health -m check
  ```
  Run all checks but don't perform any DB changes. Returns 0 (zero) if some checks
  found something and non-zero if something was found by any check. Useful to run
  as cron job to see if any check "goes red" over time after everything has been fixed once.

* Execute mode: `--mode execute` or `-m execute`:
  ```
  $ bin/typo3 dbdoctor:health -m execute
  ```
  Blindly execute without further questions! This will execute all update and delete queries
  dbdoctor suggests! This is a potentially destructive auto-operation if you trust the command,
  which you shouldn't ;-) Did you create a DB backup before?

* Log execute queries to file: `--file` or `-f`:
  ```
  $ bin/typo3 dbdoctor:health -f /tmp/foo.sql
  ```
  ```
  $ bin/typo3 dbdoctor:health -f /tmp/dbdoctor-my-instance-`date +%Y-%m-%d-%H-%M-%S`.sql
  ```
  Log all data changing queries to a file. The argument must be an *absolute file name*.
  **Never put such a file into the public web folder of your instance**. This is available in
  "interactive" and "execute" mode. Executed data changing queries are not only displayed, but also
  logged to a file. This can be useful if the command has been executed on a staging system
  using a current live database image: The queries can be reviewed again and then executed
  on a live instance using something like `mysql my_database < file.sql` or similar for other
  DBMS.


# Existing health checks

Single tests are described in details when running the CLI command. Rough overview:

* Page tree integrity checks
* FAL related sys_file_reference and friends checks
* Language handling related checks
* Workspace related checks
* Inline parent-child relation related checks


# Further hints

We highly encourage admins to back up databases when working with dbdoctor. Some basic rules
regarding SQL dumps must not be forgotten when doing this:

* When dumping an existing MySQL / MariaDB database *before and after* executing the CLI command,
  it can be helpful to toggle-off the "extended inserts" option: `mysqldump` by default merges
  multiple INSERT statements into one call for efficiency and speed. This is both quicker to dump and
  to import, and consumes less disk space.

  However, when looking for single DB changes, it is much more convenient to turn this off and have
  one line for each inserted row. Tools like `diff` are then far easier to grasp when searching for
  something that eventually went wrong. Example shell commands:
  ```
  $ mysqldump --skip-extended-insert myDatabase > /tmp/myDatabase-`date +%Y-%m-%d-%H-%M-%S`-dbdoctor-before.sql
  $ bin/typo3 dbdoctor:health
  $ mysqldump --skip-extended-insert myDatabase > /tmp/myDatabase-`date +%Y-%m-%d-%H-%M-%S`-dbdoctor-after.sql
  ```
  It's always possible to deviate from with when you know what you're doing, though. I practice, it might
  be a good idea to create two dumps: One with `--skip-extended-insert` and one without. A disaster recovery
  is much quicker when loading from a file that has no "one row per line", but to debug, it's much easier
  to diff dumps that were based on skipped extended inserts.

* When dumping databases, it is a **crucial security measure** to **never** put such dumps into
  a public directory accessible by a web server or some third party server user. Violating this
  basic rule is a common source of data leaks in the wild! There is no excuse to get this wrong.
  It is also a good idea to put SQL files at a place that is rotated into backups to allow debugging
  later in case issues only pop up after a while. To follow GDPR rules, those files should still be
  removed at some point!

* When dumping databases, it is often a good idea to gzip .sql files: This typically reduces file size
  by around factor eight. Lets safe some precious server disk and backup size! It's also possible to
  directly 'pipe' to gzip when dumping. Either do that, or remember to gzip stuff before logging out
  of a system.


# FAQ

* Will the functionality be made available in a backend GUI?
  > No. CLI is the only sane way for these kind of things.

* Will support for TYPO3 v10 or other older core versions added?
  > No. TYPO3 v11 had quite a few DB changes, and it is not planned to implement
  > a v10 backwards compatible layer.


# Tagging and releasing

[packagist.org](https://packagist.org/packages/lolli/dbdoctor) is enabled via the casual github hook.
TER releases are created by the "publish.yml" github workflow when tagging versions
using [tailor](https://github.com/typo33/tailor). The commit message of the tagged commit is
used as TER upload comment.

Example:

```
Build/Scripts/runTests.sh -s clean
Build/Scripts/runTests.sh -s composerUpdate
composer req --dev typo3/tailor
.Build/bin/tailor set-version 0.3.2
composer rem --dev typo3/tailor
git commit -am "[RELEASE] 0.3.2 Added some basic inline foreign field related checks"
git tag 0.3.2
git push
git push --tags
```
