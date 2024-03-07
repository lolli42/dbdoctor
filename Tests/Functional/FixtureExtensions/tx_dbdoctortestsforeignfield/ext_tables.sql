CREATE TABLE tt_content
(
    tx_dbdoctortestsforeignfield_hotels int(11) DEFAULT '0' NOT NULL
);

CREATE TABLE tx_dbdoctortestsforeignfield_hotels
(
    parentid int(11) DEFAULT '0' NOT NULL,
    title tinytext NOT NULL
);
