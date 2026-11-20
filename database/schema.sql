-- Student Grade Portal schema (MariaDB 10.4+ / MySQL 8+)
-- Normally loaded by: php database/install.php

CREATE TABLE users (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username             VARCHAR(50)  NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  full_name            VARCHAR(150) NOT NULL,
  role                 ENUM('admin','teacher') NOT NULL DEFAULT 'teacher',
  is_active            TINYINT(1)   NOT NULL DEFAULT 1,
  must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB;

CREATE TABLE students (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lrn         CHAR(12)    NOT NULL,
  last_name   VARCHAR(80) NOT NULL,
  first_name  VARCHAR(80) NOT NULL,
  middle_name VARCHAR(80) NULL,
  sex         ENUM('M','F') NULL,
  birthdate   DATE        NOT NULL,
  created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_students_lrn (lrn),
  KEY idx_students_name (last_name, first_name),
  CONSTRAINT chk_students_lrn CHECK (lrn REGEXP '^[0-9]{12}$')
) ENGINE=InnoDB;

CREATE TABLE school_years (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label     VARCHAR(9) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_school_years_label (label)
) ENGINE=InnoDB;

CREATE TABLE subjects (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(20)  NOT NULL,
  name       VARCHAR(100) NOT NULL,
  type       ENUM('core','applied','specialized') NOT NULL DEFAULT 'core',
  sort_order SMALLINT     NOT NULL DEFAULT 0,
  UNIQUE KEY uq_subjects_code (code)
) ENGINE=InnoDB;

CREATE TABLE sections (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  school_year_id INT UNSIGNED     NOT NULL,
  grade_level    TINYINT UNSIGNED NOT NULL,
  strand         VARCHAR(50)      NOT NULL DEFAULT '',
  name           VARCHAR(50)      NOT NULL,
  adviser_id     INT UNSIGNED     NULL,
  UNIQUE KEY uq_sections_name (school_year_id, grade_level, name),
  UNIQUE KEY uq_sections_id_year (id, school_year_id),
  CONSTRAINT fk_sections_year    FOREIGN KEY (school_year_id) REFERENCES school_years (id),
  CONSTRAINT fk_sections_adviser FOREIGN KEY (adviser_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_sections_grade  CHECK (grade_level BETWEEN 1 AND 12)
) ENGINE=InnoDB;

-- One section per learner per school year. The composite foreign key keeps
-- school_year_id equal to the section's own school year.
CREATE TABLE enrollments (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id     INT UNSIGNED NOT NULL,
  section_id     INT UNSIGNED NOT NULL,
  school_year_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_enrollments_student_year (student_id, school_year_id),
  KEY idx_enrollments_section (section_id, school_year_id),
  CONSTRAINT fk_enrollments_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
  CONSTRAINT fk_enrollments_section FOREIGN KEY (section_id, school_year_id)
    REFERENCES sections (id, school_year_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Which teacher handles which subject in which section, and in which semester
-- (1st semester = Quarters 1–2, 2nd semester = Quarters 3–4)
CREATE TABLE class_assignments (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  section_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  teacher_id INT UNSIGNED NOT NULL,
  semester   TINYINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_class_section_subject (section_id, subject_id),
  KEY idx_class_teacher (teacher_id),
  CONSTRAINT fk_class_section FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE CASCADE,
  CONSTRAINT fk_class_subject FOREIGN KEY (subject_id) REFERENCES subjects (id),
  CONSTRAINT fk_class_teacher FOREIGN KEY (teacher_id) REFERENCES users (id),
  CONSTRAINT chk_class_semester CHECK (semester IN (1, 2))
) ENGINE=InnoDB;

-- One quarterly grade per learner per class. Re-uploading overwrites it.
-- The app only accepts the two quarters of the class's semester.
CREATE TABLE grades (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id          INT UNSIGNED     NOT NULL,
  class_assignment_id INT UNSIGNED     NOT NULL,
  quarter             TINYINT UNSIGNED NOT NULL,
  grade               DECIMAL(5,2)     NOT NULL,
  updated_by          INT UNSIGNED     NULL,
  updated_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_grades (student_id, class_assignment_id, quarter),
  KEY idx_grades_class (class_assignment_id, quarter),
  CONSTRAINT fk_grades_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE CASCADE,
  CONSTRAINT fk_grades_class   FOREIGN KEY (class_assignment_id) REFERENCES class_assignments (id) ON DELETE CASCADE,
  CONSTRAINT fk_grades_user    FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_grades_quarter CHECK (quarter BETWEEN 1 AND 4),
  CONSTRAINT chk_grades_range   CHECK (grade BETWEEN 0 AND 100)
) ENGINE=InnoDB;

-- A row here means learners can see that class's grades for that quarter
CREATE TABLE grade_publications (
  class_assignment_id INT UNSIGNED     NOT NULL,
  quarter             TINYINT UNSIGNED NOT NULL,
  published_by        INT UNSIGNED     NULL,
  published_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (class_assignment_id, quarter),
  CONSTRAINT fk_publications_class FOREIGN KEY (class_assignment_id) REFERENCES class_assignments (id) ON DELETE CASCADE,
  CONSTRAINT fk_publications_user  FOREIGN KEY (published_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE upload_log (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id             INT UNSIGNED     NOT NULL,
  class_assignment_id INT UNSIGNED     NOT NULL,
  quarter             TINYINT UNSIGNED NOT NULL,
  original_filename   VARCHAR(255)     NOT NULL,
  rows_saved          INT UNSIGNED     NOT NULL DEFAULT 0,
  rows_rejected       INT UNSIGNED     NOT NULL DEFAULT 0,
  created_at          DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_upload_class (class_assignment_id),
  CONSTRAINT fk_upload_user  FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_upload_class FOREIGN KEY (class_assignment_id) REFERENCES class_assignments (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope        VARCHAR(10)  NOT NULL,
  identifier   VARCHAR(100) NOT NULL,
  ip           VARCHAR(45)  NOT NULL,
  attempted_at DATETIME     NOT NULL,
  KEY idx_attempts_identifier (scope, identifier, attempted_at),
  KEY idx_attempts_ip (scope, ip, attempted_at)
) ENGINE=InnoDB;
