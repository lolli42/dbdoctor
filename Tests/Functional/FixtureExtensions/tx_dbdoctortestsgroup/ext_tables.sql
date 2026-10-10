CREATE TABLE tx_dbdoctortestsgroup_item
(
    title tinytext NOT NULL,
    relations_csv text,
    relations_mm int(11) DEFAULT '0' NOT NULL,
    relations_mm_single int(11) DEFAULT '0' NOT NULL,
    relations_mm_without_match_fields int(11) DEFAULT '0' NOT NULL
);
