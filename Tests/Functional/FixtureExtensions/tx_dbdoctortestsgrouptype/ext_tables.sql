CREATE TABLE tx_dbdoctortestsgrouptype_item
(
    title tinytext NOT NULL,
    record_type varchar(255) DEFAULT '' NOT NULL,
    relations_csv text,
    relations_mm int(11) DEFAULT '0' NOT NULL,
    relations_mm_changed int(11) DEFAULT '0' NOT NULL
);

CREATE TABLE tx_dbdoctortestsgrouptype_fallback
(
    title tinytext NOT NULL,
    record_type varchar(255) DEFAULT '' NOT NULL,
    relations_csv text,
    relations_mm int(11) DEFAULT '0' NOT NULL
);

# The core creates "fieldname" only for fields allowing multiple tables, it is used by MM_match_fields here.
CREATE TABLE tx_dbdoctortestsgrouptype_item_mm
(
    uid_local int(11) unsigned DEFAULT '0' NOT NULL,
    uid_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    fieldname varchar(64) DEFAULT '' NOT NULL,
    sorting int(11) unsigned DEFAULT '0' NOT NULL,
    sorting_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    PRIMARY KEY (uid_local, uid_foreign, fieldname)
);
