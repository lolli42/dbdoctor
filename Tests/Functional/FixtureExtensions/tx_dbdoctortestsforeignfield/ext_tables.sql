CREATE TABLE tt_content
(
    tx_dbdoctortestsforeignfield_hotels int(11) DEFAULT '0' NOT NULL
);

CREATE TABLE tx_dbdoctortestsforeignfield_hotels
(
    parentid int(11) DEFAULT '0' NOT NULL,
    title tinytext NOT NULL
);

CREATE TABLE pages
(
    tx_dbdoctortestsforeignfield_items int(11) DEFAULT '0' NOT NULL
);

CREATE TABLE tt_content
(
    tx_dbdoctortestsforeignfield_items int(11) DEFAULT '0' NOT NULL,
    tx_dbdoctortestsforeignfield_items2 int(11) DEFAULT '0' NOT NULL
);

CREATE TABLE tx_dbdoctortestsforeignfield_items
(
    parentid int(11) DEFAULT '0' NOT NULL,
    parenttable varchar(255) DEFAULT '' NOT NULL,
    parentfield varchar(255) DEFAULT '' NOT NULL,
    title tinytext NOT NULL
);
