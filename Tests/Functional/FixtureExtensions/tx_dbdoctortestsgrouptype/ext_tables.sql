CREATE TABLE tx_dbdoctortestsgrouptype_item
(
    title tinytext NOT NULL,
    record_type varchar(255) DEFAULT '' NOT NULL,
    relations_csv text
);

CREATE TABLE tx_dbdoctortestsgrouptype_fallback
(
    title tinytext NOT NULL,
    record_type varchar(255) DEFAULT '' NOT NULL,
    relations_csv text
);
