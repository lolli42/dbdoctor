CREATE TABLE tx_dbdoctortestsinline_parent
(
    title tinytext NOT NULL,
    children_list text,
    children_mm int(11) DEFAULT '0' NOT NULL
);

CREATE TABLE tx_dbdoctortestsinline_child
(
    title tinytext NOT NULL
);

CREATE TABLE tx_dbdoctortestsinline_parent_mm
(
    uid_local int(11) unsigned DEFAULT '0' NOT NULL,
    uid_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    sorting int(11) unsigned DEFAULT '0' NOT NULL,
    sorting_foreign int(11) unsigned DEFAULT '0' NOT NULL,
    PRIMARY KEY (uid_local, uid_foreign)
);
