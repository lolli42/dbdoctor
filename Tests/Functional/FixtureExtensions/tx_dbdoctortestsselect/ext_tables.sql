CREATE TABLE tx_dbdoctortestsselect_item
(
    title tinytext NOT NULL,
    record_type varchar(255) DEFAULT '' NOT NULL,
    relations text,
    relations_mm int(11) DEFAULT '0' NOT NULL,
    relation_single int(11) DEFAULT '0' NOT NULL,
    category_single int(11) DEFAULT '0' NOT NULL,
    category_multi text
);

CREATE TABLE tx_dbdoctortestsselect_item_mm
(
    uid_local int(11) unsigned DEFAULT '0' NOT NULL,
    uid_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    sorting int(11) unsigned DEFAULT '0' NOT NULL,
    sorting_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    PRIMARY KEY (uid_local, uid_foreign)
);
