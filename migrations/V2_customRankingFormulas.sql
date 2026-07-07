-- Migration: Custom ranking formula support on eventRankings
-- customSourceN holds the user's raw formula text (NULL = tier is a picked field).
-- customFallbackN holds the user's divide-by-zero fallback literal for redisplay.
-- orderByField/displayField widened to hold compiled SQL expressions
-- (division guards roughly triple the expression length).

ALTER TABLE eventRankings
  ADD COLUMN `customSource1` text DEFAULT NULL,
  ADD COLUMN `customFallback1` varchar(32) DEFAULT NULL,
  ADD COLUMN `customSource2` text DEFAULT NULL,
  ADD COLUMN `customFallback2` varchar(32) DEFAULT NULL,
  ADD COLUMN `customSource3` text DEFAULT NULL,
  ADD COLUMN `customFallback3` varchar(32) DEFAULT NULL,
  ADD COLUMN `customSource4` text DEFAULT NULL,
  ADD COLUMN `customFallback4` varchar(32) DEFAULT NULL,
  MODIFY `orderByField1` varchar(1024) NOT NULL DEFAULT 'score',
  MODIFY `orderByField2` varchar(1024) DEFAULT NULL,
  MODIFY `orderByField3` varchar(1024) DEFAULT NULL,
  MODIFY `orderByField4` varchar(1024) DEFAULT NULL,
  MODIFY `displayField1` varchar(1024) DEFAULT 'score',
  MODIFY `displayField2` varchar(1024) DEFAULT NULL,
  MODIFY `displayField3` varchar(1024) DEFAULT NULL,
  MODIFY `displayField4` varchar(1024) DEFAULT NULL,
  MODIFY `displayField5` varchar(1024) DEFAULT NULL;
