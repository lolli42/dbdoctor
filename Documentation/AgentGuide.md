# dbdoctor for agents

This guide is for coding agents that help a developer judge what `dbdoctor:health` found in a
TYPO3 instance. It describes the JSON report of dbdoctor and a way through it that keeps the
number of calls and tokens low. The report is **experimental**: While its `schemaVersion` is 0.x,
its structure may change with any release. Check classes and their order are no API at all.

The goal is a verdict the developer can trust: Which findings are harmless, which need their
eye, and why.


## Rules

* Read only. Use `--mode check --format json`, nothing else. Never run `--mode execute` or the
  interactive mode, never change the database yourself. Fixing is the developer's decision.
* Prefer `--output <directory>`, see below. On stdout, always add `--compact`: The report is
  about half the size without whitespace.
* The default `--tier structure` has uids, pids, TCA control fields and timestamps, no content.
  `--tier labels` adds titles and other TCA label fields, cut to 80 characters. They help to
  identify records, but they are content of the instance: Ask the developer before using them.
* Run dbdoctor with the TYPO3 CLI of the instance from its project root, for example
  `vendor/bin/typo3` or `bin/typo3`: TYPO3 finds its installation relative to the current
  directory. In a ddev project, use `ddev exec vendor/bin/typo3`; a relative `--output`
  directory is then relative to the project root in the container, which is the project
  directory on the host as well. A run takes seconds on small and minutes on large
  instances. `-f` is the SQL log of execute mode, not a report file.
* The exit code is the one of check mode: 0 all ok, 1 findings, 4 error. Errors in options
  come as JSON with an `error` object.


## How dbdoctor works

dbdoctor is a chain of checks, run in a fixed order. Each check finds records that violate one
rule, and knows exactly one fix per finding: There are no alternatives to choose from. Later
checks rely on earlier checks having fixed their findings: A record an early check deletes is
no finding of a later check any more, a record an early check moves gets new findings later.
The developer runs dbdoctor until check mode is green, a single run does not guarantee that.

This is why only the **first check that is not ok** has final results. Each check has a
`status`:

* `ok`: No findings.
* `findings`: Something to fix, the check has counts, facets and findings.
* `skipped`: The check ran into an unfixed finding of an earlier check it relies on, see
  `skipReason`.
* `not-run`: With the default `--scope until-first-finding`, checks after the first one that
  is not ok do not run.

With `--scope all`, all checks run, and every result after the first check that is not ok is
`preliminary: true`. Use it for an overview, and keep in mind that preliminary findings may
vanish or change once the earlier checks are fixed. A preliminary finding is likely to stay
when its records are not among the records the earlier not ok checks change.


## Workflow

Write the report into a directory once, then read its files in steps. Each question is
answered from the same snapshot, and nothing has to be read at once:

```bash
vendor/bin/typo3 dbdoctor:health -m check --format json --scope all --output var/dbdoctor/report-1
```

The directory must not exist yet. stdout names the index file. The files contain record data:
Keep them out of public folders, and remove the directory when done.

1. **Overview**: Read `index.json`: run data, sites, and per check `position`, `class`,
   `status`, `preliminary`. Checks with findings have `title`, `counts`, `file` and
   `findingsFile`. Note the first check with status `findings` or `skipped`: Only its results
   are final.
2. **One check**: Read its `file`: description, counts, facets and groups. Facets and groups
   cover all findings.
3. **Samples**: The `findingsFile` has all findings of the check, one JSON object per line.
   Each group names a few finding ids as `samples`: `grep '"id":"0123456789abcdef"'
   checks/55-…findings.jsonl`. Search for other findings by table, uid or reason code, for
   example `grep -m 5 '"code":"parent-missing"' checks/55-…findings.jsonl` or
   `grep '"table":"tt_content","uid":123,' checks/*.findings.jsonl`. A findings file of a
   few findings is quickest read completely; do not read a large one completely.
   `pages.jsonl` has the rootline of each page with findings, one page per line:
   `grep '^{"uid":42,' pages.jsonl`. `tables.jsonl` has the TCA roles, row count and
   inline parents of each table, one table per line: `grep '^{"table":"tt_content"'
   tables.jsonl`.
4. **Judge** groups of findings, see below, and repeat 2. and 3. for the next check.

Without the option to write files, the same works on stdout with smaller calls:
`--scope all --limit 0 --compact` is a summary of all checks without findings, `--check <Class>`
(repeatable, short class name works) reports one check with up to `--limit` findings (default
100), `--context none` leaves out rootlines and related records. `--table <table>`, `--uid
<uid>` (with `--table`), `--pid <page>` and `--subtree <page>` narrow findings down, counts and
facets are then of the matching findings, `counts.total` has all findings of the check, and a
check without matching findings is listed with `filterMatches: false`. For a check after the
first one that is not ok, add `--scope all`, otherwise it is `not-run`. `--limit 0` is a
summary, not "unlimited". The filters work with `--output` as well.


## Reading a check

* `title`, `description`: What the check looks for and what its fix does. Read the description
  before judging, it often says why a fix is safe.
* `tags`: Kinds of changes the check does: `remove` (delete rows), `soft-delete`,
  `workspace-remove`, `update-fields`, `insert`, and `risky` for checks whose fix may change
  what the frontend shows.
* `keyTypes`: What a finding is about: `record` (one row), `record-field` (one field of a row,
  a record may have several findings), `mm` (rows of an MM table of one local record).
* `counts`: Four numbers in different units. `findings`, `records` (distinct records),
  `writes` (single changes), and per table in `byTable` the affected `rows`, their `share` of
  the table and `suspicious`. MM findings have no record: They count in `findings` but not in
  `records`, and one MM finding (all rows of one missing local record) can stand for many
  `rows`.
* `facets`: Counts over all findings of the check, also when findings are cut by `--limit`.
  `hidden`, `language` and `tstampAge` count only findings with a record, `rootPage` only
  findings with a page, so their sums can be lower than `findings`.
  * `operation`: Changes per operation: `update`, `soft-delete`, `delete`, `insert`, `mm-delete`.
  * `reason`: Findings per reason code.
  * `state`: The record is `live`, `deleted` (soft-deleted), `workspace` (a workspace
    version), or `none` (MM findings have no record).
  * `stateByOperation`: Operations per state.
  * `hidden`, `language`, `tstampAge` (`<30d`, `<1y`, `<5y`, `older`, `none`).
  * `rootPage`: The site root page above the record's page, `none` if the page is in no site.
  * `writePatterns`: Distinct kinds of changes: table, field and the kind of the new value:
    `0`, `1`, `empty`, `role:<role>` (the value of another field of the record, for example
    `role:l10nParent`), or `value`.
* `findings`: Each with
  * `id`: Stable for the same check and key.
  * `key`: `type`, `table` and `uid` (and `field`), or for `mm`: `mmTable`, `uidLocal`,
    `localTable`, `field` and `matchFields`.
  * `pid`: The page of the record.
  * `reason`: `code` and `parameters` name the cause and the related records, for example
    `parent-missing` with the uid of the parent. `message` is prose for humans, do not parse it.
    Some checks have no reason (`null`, `none` in the facet): The check rule itself is the
    reason.
  * `evidence`: Extra data of some checks, like the number of MM rows.
  * `record`: uid, pid and TCA control fields under names that are the same for all tables:
    `deleted`, `hidden`, `starttime`, `endtime`, `language`, `l10nParent`, `l10nSource`, `type`,
    `tstamp`, `crdate`, `cruserId`, and `workspace` with `id`, `state` and `oid`. Only fields
    the table has. Workspace `id` 0 is live. `state` is the core's t3ver_state: 0 a changed
    version of the live record `oid` (or a live record), 1 a record new in the workspace,
    2 a delete placeholder, 4 a move pointer. `hidden` is the field value: A workspace
    version marked `visible` is still not shown to visitors before publishing.
  * `writes`: The changes dbdoctor would do: `seq` (their order within the check),
    `operation`, `table`, `uid`, for updates `set` with `old` and `new` per field, `values` of
    an insert, `where` of an MM delete.
  * `context`: The language parent `l10nParent` and translation source `l10nSource` of the
    record, `missing` if they do not exist. The translation source is left out when it is the
    language parent.
* `groups`: Findings of the same `table`, `reason`, `state`, `hidden`, `operation` (the
  operations of a finding joined with `+`), and `inSite` (whether the page is in a site), with
  `count` and up to three finding ids as `samples`. Largest groups first. `hidden` is `null`
  for tables without hidden field and for MM findings, `inSite` for findings without page.
* `pages` (top level, `pages.jsonl` with `--output`): Per page of the findings its `rootline`
  from the tree root down with the `doktype` of each page (1 standard, 254 folder, 4 shortcut,
  …), pages flagged `deleted`, `hidden`, `missing` or with `workspace`, its `rootPageId`, the
  root page of its site or `null`, and its `translations`: uid and language of each page
  translation, flagged `deleted`, `hidden` or with `workspace`.
* `tables` (top level, `tables.jsonl` with `--output`): Per table of the findings and changes
  `rowCount`, the `roles` (role name to field name of the table, to map the record names back
  to columns), and
  `inlineParents`: All tables and fields that can own records of an inline child table, with
  their foreign field and match fields.
* `sites` (top level): Root page and languages of each site. Languages other than the default
  one have `fallbackType` and `fallbacks`: With `strict`, a page without translation in this
  language is not available in it; with `fallback`, it is shown in the first fallback language
  that has a translation, with the content of that language; `free` shows records of the
  language only.


## Judging

Judge groups, not single findings: A check with tens of thousands of findings has few groups.
Give each group a verdict, name the group values and facets it is based on, and look at the
samples of each group before concluding. Groups do not split by field or write pattern: When
`writePatterns` of a check show different fields, look at samples of each.

* **Frontend impact** is what the developer cares about most. dbdoctor tries to keep the
  frontend unchanged, the README section "Impact on Frontend rendering" explains where it can
  not. Findings on records that are soft-deleted, workspace versions, or on pages outside of
  all sites (`rootPage` `none`) do not change what visitors see when fixed. Hidden records and
  records with a past `endtime` usually do not either.
* **Operations on live records** are the interesting part: `stateByOperation` shows if deletes
  or updates hit live records. An update that fills a field from another field of the record
  (`role:...` patterns) is usually a consistency fix; a delete of a live, visible record on a
  page of a site needs a look at samples.
* **Few write patterns** mean a uniform fix: One pattern over thousands of findings is one
  decision.
* **Age**: Old untouched records are often leftovers of old imports, migrations or extensions.
  Recent ones may point to a bug that still produces broken records: Worth telling the
  developer, a fix alone will not stop it.
* **`suspicious` is a hint, not a verdict.** It marks a table where a check flags more than
  half of the rows, and more than 100. That often means the check and the TCA disagree, for
  example an extension uses a table in a way dbdoctor does not expect, and the findings are
  no broken data. It can be valid as well, for example an MM table whose rows mostly point to
  records that were deleted. Look at samples and the reason parameters.
* **Inline children**: Which records may own a child is in `tables.<table>.inlineParents`.
  A child whose parent is missing according to one parent table may belong to another one:
  Check the match fields.
* **Content of a language on a page**: Localized content is shown only on pages available in
  its language. `pages.<pid>.translations` and the fallback of the site language tell whether
  it is: A content element in language 1 on a page without translation 1 is not shown in
  language 1, the page is not available or shows the fallback language instead.
* **Translations and workspaces**: `context.l10nParent`, `context.l10nSource` and
  `record.workspace` often explain a finding without further queries, for example a language
  parent pointing to a workspace version instead of a live record. Deleting workspace
  records does not change the live site, but loses unpublished work: Records new in a
  workspace (state 1) are gone for good.
* **Which side does the fix trust?** A check that updates fields keeps one field and derives
  the other from it, the `writes` and the description tell which. When the trusted field
  looks wrong itself, the fix makes the record consistent but keeps the actual error: Tell
  the developer, with the field that looks wrong and why.
* **A check may be wrong.** dbdoctor checks are rules derived from TCA; unusual setups break
  them. If samples of a group look like valid data, say so and describe why, with record keys.
  Do not suggest code changes, the maintainer of dbdoctor prefers a precise description.


## Verdict

Hand the developer a short report per check with findings:

* Check, status, whether it is preliminary, counts.
* Groups, each with a verdict: **harmless** (fixing changes nothing visible),
  **needs a look** (fix may change the frontend or loses data someone may need), or **check
  seems wrong** (findings look like valid data).
* The basis of each verdict: facet values and the samples looked at.
* The single records that need the developer's eye, with table, uid, page and why.
* Causes that probably still produce broken records, when groups have recent records.

The same record can be a finding of several checks, for example a file reference of a missing
record is dangling and an inline child without parent: Count it once when summing up.
Numbers in the verdict come from the report, never estimate them.


## Querying the report files

The workflow above needs no tool besides reading files and grep. For other questions, any JSON
tool works. jq is very useful when installed, for example in a report directory:

```bash
# Checks that are not ok, with their number of findings
jq -c '.checks[] | select(.status != "ok") | {position, class, status, preliminary, findings: .counts.findings}' index.json
# Key, reason code and changes of each finding of a check
jq -c '{key, reason: .reason.code, writes: [.writes[] | {operation, table, uid, set}]}' checks/55-GroupFieldRelationMissing.findings.jsonl
# Findings on live records only
jq -c 'select(.record != null and (.record.deleted // 0) == 0 and (.record.workspace.id // 0) == 0) | .key' checks/55-GroupFieldRelationMissing.findings.jsonl
# Findings per page
jq -r '.pid' checks/55-GroupFieldRelationMissing.findings.jsonl | sort | uniq -c | sort -rn | head
```

PHP is always available on a TYPO3 host and works as fallback:

```bash
php -r 'foreach (file($argv[1]) as $line) { $f = json_decode($line, true); if (($f["record"]["deleted"] ?? 0) === 0) { echo json_encode($f["key"]), PHP_EOL; } }' checks/55-GroupFieldRelationMissing.findings.jsonl
```
