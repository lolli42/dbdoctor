CREATE TABLE tx_dbdoctortestssync_item
(
    title tinytext NOT NULL,
    relation_select varchar(255) DEFAULT '',
    relation_group text,
    relation_category text,
    static_select varchar(255) DEFAULT ''
);
