<?php
/**
 * Learner form fields, shared by "Add a learner" and "Edit learner".
 * @var array $form        lrn, last_name, first_name, middle_name, sex, birthdate, section_id
 * @var array $sectionOpts sections in the active school year
 * @var ?array $sy         active school year
 */
?>
<div class="row g-3">
    <div class="col-12">
        <label class="form-label" for="f-lrn">LRN</label>
        <input class="form-control lrn" id="f-lrn" name="lrn" value="<?= e($form['lrn']) ?>" inputmode="numeric" maxlength="16" required>
    </div>
    <div class="col-sm-6">
        <label class="form-label" for="f-last">Last name</label>
        <input class="form-control" id="f-last" name="last_name" value="<?= e($form['last_name']) ?>" maxlength="80" required>
    </div>
    <div class="col-sm-6">
        <label class="form-label" for="f-first">First name</label>
        <input class="form-control" id="f-first" name="first_name" value="<?= e($form['first_name']) ?>" maxlength="80" required>
    </div>
    <div class="col-sm-6">
        <label class="form-label" for="f-middle">Middle name <span class="text-secondary small">(optional)</span></label>
        <input class="form-control" id="f-middle" name="middle_name" value="<?= e($form['middle_name']) ?>" maxlength="80">
    </div>
    <div class="col-sm-6">
        <label class="form-label" for="f-sex">Sex</label>
        <select class="form-select" id="f-sex" name="sex">
            <option value="">—</option>
            <option value="M" <?= $form['sex'] === 'M' ? 'selected' : '' ?>>Male</option>
            <option value="F" <?= $form['sex'] === 'F' ? 'selected' : '' ?>>Female</option>
        </select>
    </div>
    <div class="col-sm-6">
        <label class="form-label" for="f-birthdate">Birthdate</label>
        <input class="form-control" type="date" id="f-birthdate" name="birthdate" value="<?= e($form['birthdate']) ?>" max="<?= e(date('Y-m-d')) ?>" required>
    </div>
    <div class="col-sm-6">
        <label class="form-label" for="f-section">Section<?= $sy ? ' (S.Y. ' . e($sy['label']) . ')' : '' ?></label>
        <select class="form-select" id="f-section" name="section_id" <?= $sy ? '' : 'disabled' ?>>
            <option value="0">Not enrolled</option>
            <?php foreach ($sectionOpts as $opt): ?>
                <option value="<?= (int) $opt['id'] ?>" <?= (int) $form['section_id'] === (int) $opt['id'] ? 'selected' : '' ?>><?= e(section_label($opt)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
