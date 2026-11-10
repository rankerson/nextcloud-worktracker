<?php
script('worktracker', 'main');
style('worktracker', 'main');
?>
<div id="worktracker-app" class="section worktracker-app-page">
    <h1><?php p($l->t('Work Tracker')); ?></h1>
    <div id="worktracker-main-content" class="worktracker-loading"><?php p($l->t('Loading…')); ?></div>
</div>
