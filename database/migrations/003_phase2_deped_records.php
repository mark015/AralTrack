<?php
// Phase 2: learner profiles (for SF1), learner status (graduated, transferred…), observed values (SF9),
// and parents acknowledging report cards.
declare(strict_types=1);

return static function (PDO $pdo): void {
    $columns = [
        'photo_path'            => 'VARCHAR(255) NULL',
        'address'               => 'VARCHAR(255) NULL',
        'mother_tongue'         => 'VARCHAR(60) NULL',
        'religion'              => 'VARCHAR(60) NULL',
        'father_name'           => 'VARCHAR(150) NULL',
        'mother_name'           => 'VARCHAR(150) NULL',
        'guardian_name'         => 'VARCHAR(150) NULL',
        'guardian_relationship' => 'VARCHAR(40) NULL',
        'contact_number'        => 'VARCHAR(40) NULL',
        'status'                => "ENUM('active','graduated','transferred_out','dropped') NOT NULL DEFAULT 'active'",
        'status_note'           => 'VARCHAR(255) NULL',
    ];
    foreach ($columns as $name => $definition) {
        if (!column_exists($pdo, 'students', $name)) {
            $pdo->exec("ALTER TABLE students ADD COLUMN {$name} {$definition}");
        }
    }

    // SF9 "Learner's Observed Values": 7 behavior statements, rated per quarter by the adviser.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS observed_values (
           student_id     INT UNSIGNED     NOT NULL,
           school_year_id INT UNSIGNED     NOT NULL,
           quarter        TINYINT UNSIGNED NOT NULL,
           statement      TINYINT UNSIGNED NOT NULL,
           rating         ENUM('AO','SO','RO','NO') NOT NULL,
           updated_by     INT UNSIGNED     NULL,
           updated_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
           PRIMARY KEY (student_id, school_year_id, quarter, statement),
           CONSTRAINT fk_values_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
           CONSTRAINT fk_values_year    FOREIGN KEY (school_year_id) REFERENCES school_years (id) ON DELETE CASCADE,
           CONSTRAINT fk_values_user    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
         ) ENGINE=InnoDB"
    );

    // A parent confirms they have seen a semester's report card (replaces the SF9 signature).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS report_acknowledgements (
           student_id      INT UNSIGNED     NOT NULL,
           school_year_id  INT UNSIGNED     NOT NULL,
           semester        TINYINT UNSIGNED NOT NULL,
           parent_id       INT UNSIGNED     NOT NULL,
           acknowledged_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
           PRIMARY KEY (student_id, school_year_id, semester, parent_id),
           CONSTRAINT fk_ack_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
           CONSTRAINT fk_ack_year    FOREIGN KEY (school_year_id) REFERENCES school_years (id) ON DELETE CASCADE,
           CONSTRAINT fk_ack_parent  FOREIGN KEY (parent_id) REFERENCES parents (id) ON DELETE CASCADE
         ) ENGINE=InnoDB"
    );
};
