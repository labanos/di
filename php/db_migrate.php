<?php
// Schema migrations — idempotent, safe to call on every request

function run_migrations($pdo) {

    $pdo->exec("CREATE TABLE IF NOT EXISTS players (
        id     INT AUTO_INCREMENT PRIMARY KEY,
        name   VARCHAR(100) NOT NULL,
        team   ENUM('blue','red') NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uniq_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tournaments (
        id   INT AUTO_INCREMENT PRIMARY KEY,
        year INT NOT NULL,
        UNIQUE KEY uniq_year (year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rounds (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        tournament_id INT NOT NULL,
        round_number  TINYINT NOT NULL,
        format        ENUM('fourball','greensome','foursome','singles') NOT NULL,
        UNIQUE KEY uniq_round (tournament_id, round_number),
        CONSTRAINT fk_rounds_tourn FOREIGN KEY (tournament_id) REFERENCES tournaments(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS matches (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        round_id     INT NOT NULL,
        match_number TINYINT NOT NULL,
        UNIQUE KEY uniq_match (round_id, match_number),
        CONSTRAINT fk_matches_round FOREIGN KEY (round_id) REFERENCES rounds(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS match_results (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        match_id   INT NOT NULL,
        player_id  INT NOT NULL,
        points     TINYINT NOT NULL COMMENT '0=loss 1=halved 2=win',
        ups        INT NOT NULL DEFAULT 0 COMMENT 'positive=win margin negative=loss margin 0=halved',
        partner_id INT DEFAULT NULL,
        UNIQUE KEY uniq_result (match_id, player_id),
        CONSTRAINT fk_mr_match   FOREIGN KEY (match_id)   REFERENCES matches(id),
        CONSTRAINT fk_mr_player  FOREIGN KEY (player_id)  REFERENCES players(id),
        CONSTRAINT fk_mr_partner FOREIGN KEY (partner_id) REFERENCES players(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
