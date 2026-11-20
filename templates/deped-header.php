<?php /** DepEd-style form heading with the school's details. */ ?>
<div class="deped">
    <?= brand_mark(44) ?>
    <div class="lines">
        <div>Republic of the Philippines · Department of Education</div>
        <div><?= e(trim(setting('school_region') . ' · ' . setting('school_division'), ' ·')) ?></div>
        <div class="school"><?= e(setting('school_name')) ?></div>
        <div class="small"><?= e(setting('school_address')) ?><?= setting('school_id') ? ' · School ID ' . e(setting('school_id')) : '' ?></div>
    </div>
</div>
