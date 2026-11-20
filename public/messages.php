<?php
// Messages between parents and teachers (adviser or subject teachers), kept inside the portal: no email, no SMS.
//   messages.php          conversations
//   messages.php?new=1    start a conversation
//   messages.php?t=ID     one conversation, with a reply box
require dirname(__DIR__) . '/src/bootstrap.php';

if (current_staff()) {
    $me = require_staff();
    $who = 'staff';
} elseif (current_parent()) {
    $me = require_parent();
    $who = 'parent';
} else {
    flash('warning', 'Please sign in to see your messages.');
    redirect('parent/login.php');
}
$myId = (int) $me['id'];
Access::requireModule('messages');
$error = null;
$form = ['to' => post('to') ?: get_str('to'), 'subject' => '', 'body' => ''];
const MESSAGE_MAX = 2000;
const MESSAGES_PER_HOUR = 40;

/** Who this person may start a conversation with: "studentId:otherId" => [group, label]. */
$contacts = static function () use ($who, $me): array {
    $out = [];
    if ($who === 'parent') {
        $children = db_all(
            'SELECT st.id, st.first_name, st.last_name, st.middle_name FROM parent_students ps JOIN students st ON st.id = ps.student_id WHERE ps.parent_id = ? ORDER BY st.first_name',
            [$me['id']]
        );
        foreach ($children as $child) {
            foreach (Messages::staffForChild((int) $child['id']) as $s) {
                $out[$child['id'] . ':' . $s['id']] = ['About ' . student_name($child), $s['full_name'] . ' — ' . $s['role_label']];
            }
        }
    } else {
        foreach (Messages::parentsForStaff($me) as $row) {
            $out[$row['student_id'] . ':' . $row['parent_id']] = [
                section_label($row),
                student_name($row) . ' — ' . $row['parent_name'] . ' (' . (RELATIONSHIPS[$row['relationship']] ?? $row['relationship']) . ')',
            ];
        }
    }
    return $out;
};

$readBody = static function () use (&$error): ?string {
    $body = trim(str_replace("\r\n", "\n", post('body')));
    if ($body === '') {
        $error = 'Write your message first.';
    } elseif (mb_strlen($body) > MESSAGE_MAX) {
        $error = 'Messages can be up to ' . MESSAGE_MAX . ' characters. Split it into two messages.';
    } else {
        return $body;
    }
    return null;
};

if (is_post()) {
    $action = post('action');
    if (Messages::sentLastHour($who, $myId) >= MESSAGES_PER_HOUR) {
        $error = 'You have sent many messages in the last hour. Please wait a while before sending more.';
    } elseif ($action === 'start') {
        $form = ['to' => post('to'), 'subject' => mb_substr(trim(post('subject')), 0, 200), 'body' => post('body')];
        $allowed = $contacts();
        $body = $readBody();
        if (!isset($allowed[$form['to']])) {
            $error = $who === 'parent' ? 'Choose the teacher you want to write to.' : 'Choose the parent you want to write to.';
        } elseif ($form['subject'] === '') {
            $error = 'Add a subject, e.g. "Absence on Monday" or "Question about the Q2 project".';
        } elseif ($body !== null) {
            [$studentId, $otherId] = array_map('intval', explode(':', $form['to']));
            [$parentId, $staffId] = $who === 'parent' ? [$myId, $otherId] : [$otherId, $myId];
            db_exec('INSERT INTO message_threads (parent_id, staff_id, student_id, subject) VALUES (?, ?, ?, ?)', [$parentId, $staffId, $studentId, $form['subject']]);
            $threadId = (int) db()->lastInsertId();
            Messages::send($threadId, $who, $body);
            flash('success', 'Message sent.');
            redirect('messages.php?t=' . $threadId);
        }
    } elseif ($action === 'reply') {
        $thread = Messages::thread($who, $myId, post_int('thread_id'));
        if (!$thread) {
            abort(404, 'That conversation does not exist.');
        }
        $body = $readBody();
        if ($body !== null) {
            Messages::send((int) $thread['id'], $who, $body);
            redirect('messages.php?t=' . $thread['id'] . '#latest');
        }
        $_GET['t'] = (string) $thread['id'];
        $form['body'] = post('body');
    }
}

$thread = null;
$messages = [];
if ($threadId = get_int('t')) {
    $thread = Messages::thread($who, $myId, $threadId) ?? abort(404, 'That conversation does not exist.');
    Messages::markRead($threadId, $who);
    $messages = db_all('SELECT * FROM messages WHERE thread_id = ? ORDER BY id', [$threadId]);
}
$composing = !$thread && (get_int('new') === 1 || ($error && post('action') === 'start'));
$threads = $thread || $composing ? [] : Messages::threads($who, $myId);
$other = static fn (array $t): string => $who === 'parent' ? $t['staff_name'] : $t['parent_name'];

$title = $thread ? $thread['subject'] : 'Messages';
require APP_ROOT . '/templates/header.php';
?>
<?php if ($thread): ?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="<?= e(url('messages.php')) ?>">Messages</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= e($thread['subject']) ?></li>
        </ol>
    </nav>
    <div class="card">
        <div class="card-header bg-transparent d-flex align-items-center gap-3 py-3">
            <?= avatar($other($thread)) ?>
            <div class="lh-sm">
                <div class="fw-semibold"><?= e($thread['subject']) ?></div>
                <div class="small text-secondary">
                    With <?= e($other($thread)) ?>
                    <?php if ($thread['first_name']): ?> · about <?= e(student_name($thread)) ?><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="chat" id="chat">
            <?php foreach ($messages as $i => $m):
                $mine = $m['sender_type'] === $who; ?>
                <div class="bubble <?= $mine ? 'mine' : '' ?>"<?= $i === count($messages) - 1 ? ' id="latest"' : '' ?>><?= e($m['body']) ?><span class="meta"><?= $mine ? 'You' : e($other($thread)) ?> · <?= e(when_label($m['created_at'])) ?><?php if ($mine && $m['read_at']): ?> · <i class="bi bi-check2-all" aria-hidden="true"></i> Seen<?php endif; ?></span></div>
            <?php endforeach; ?>
        </div>
        <div class="card-body border-top">
            <?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-x-octagon-fill"></i><div><?= e($error) ?></div></div><?php endif; ?>
            <form method="post" action="<?= e(url('messages.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reply">
                <input type="hidden" name="thread_id" value="<?= (int) $thread['id'] ?>">
                <label class="visually-hidden" for="reply">Reply</label>
                <textarea class="form-control mb-2" id="reply" name="body" rows="3" maxlength="<?= MESSAGE_MAX ?>" placeholder="Write a reply…" required><?= e($form['body']) ?></textarea>
                <div class="d-flex justify-content-between align-items-center gap-2">
                    <span class="small text-secondary"><i class="bi bi-shield-lock me-1"></i>Only you and <?= e($other($thread)) ?> can see this conversation.</span>
                    <button class="btn btn-primary"><i class="bi bi-send me-1"></i>Send</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        (function () { var c = document.getElementById('chat'); if (c) c.scrollTop = c.scrollHeight; })();
    </script>
<?php elseif ($composing):
    $options = $contacts(); ?>
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="<?= e(url('messages.php')) ?>">Messages</a></li>
            <li class="breadcrumb-item active" aria-current="page">New message</li>
        </ol>
    </nav>
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-body">
                    <h1 class="h5 mb-3"><i class="bi bi-pencil-square me-2"></i>New message</h1>
                    <?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-x-octagon-fill"></i><div><?= e($error) ?></div></div><?php endif; ?>
                    <?php if (!$options): ?>
                        <p class="text-secondary mb-0">
                            <?= $who === 'parent'
                                ? 'There is no one to write to yet. Your child needs to be enrolled in a section with an adviser or subject teachers this school year.'
                                : 'No parent accounts are linked to the learners in your classes yet.' ?>
                        </p>
                    <?php else: ?>
                        <form method="post" action="<?= e(url('messages.php')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="start">
                            <label class="form-label small" for="to">To</label>
                            <select class="form-select mb-2" id="to" name="to" required>
                                <option value=""><?= $who === 'parent' ? 'Choose a teacher…' : 'Choose a parent…' ?></option>
                                <?php $group = null; foreach ($options as $value => [$optGroup, $label]):
                                    if ($optGroup !== $group): ?>
                                        <?= $group !== null ? '</optgroup>' : '' ?><optgroup label="<?= e($optGroup) ?>">
                                        <?php $group = $optGroup; endif; ?>
                                    <option value="<?= e($value) ?>" <?= $form['to'] === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                                <?= $group !== null ? '</optgroup>' : '' ?>
                            </select>
                            <label class="form-label small" for="subject">Subject</label>
                            <input class="form-control mb-2" id="subject" name="subject" maxlength="200" required value="<?= e($form['subject']) ?>" placeholder="e.g. Absence on Monday">
                            <label class="form-label small" for="body">Message</label>
                            <textarea class="form-control mb-3" id="body" name="body" rows="6" maxlength="<?= MESSAGE_MAX ?>" required><?= e($form['body']) ?></textarea>
                            <button class="btn btn-primary"><i class="bi bi-send me-1"></i>Send</button>
                            <a class="btn btn-link" href="<?= e(url('messages.php')) ?>">Cancel</a>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="page-head">
        <div>
            <h1 class="h3">Messages</h1>
            <p class="sub"><?= $who === 'parent' ? "Write to your child's adviser and subject teachers." : 'Conversations with parents of your learners.' ?> Messages stay inside the portal.</p>
        </div>
        <a class="btn btn-primary" href="<?= e(url('messages.php?new=1')) ?>"><i class="bi bi-pencil-square me-1"></i>New message</a>
    </div>
    <?php if (!$threads): ?>
        <div class="card"><div class="card-body text-center py-5">
            <div class="icon-bubble mx-auto mb-3"><i class="bi bi-chat-dots"></i></div>
            <p class="fw-semibold mb-1">No messages yet.</p>
            <p class="small text-secondary mb-0"><?= $who === 'parent' ? 'Have a question about your child? Start a conversation with a teacher.' : 'When a parent writes to you, the conversation appears here.' ?></p>
        </div></div>
    <?php else: ?>
        <div class="list-group thread-list shadow-sm">
            <?php foreach ($threads as $t): ?>
                <a class="list-group-item list-group-item-action py-3 <?= $t['unread'] ? 'unread' : '' ?>" href="<?= e(url('messages.php?t=' . $t['id'] . '#latest')) ?>">
                    <?= avatar($other($t)) ?>
                    <div class="flex-grow-1 min-w-0" style="min-width: 0">
                        <div class="d-flex justify-content-between gap-2">
                            <span class="<?= $t['unread'] ? 'fw-bold' : 'fw-semibold' ?> text-truncate"><?= e($other($t)) ?></span>
                            <span class="small text-secondary text-nowrap"><?= e(when_label($t['last_message_at'])) ?></span>
                        </div>
                        <div class="small text-truncate"><?= e($t['subject']) ?><?php if ($t['first_name']): ?> <span class="text-secondary">· <?= e($t['first_name'] . ' ' . $t['last_name']) ?></span><?php endif; ?></div>
                        <div class="preview"><?= e($t['last_body']) ?></div>
                    </div>
                    <?php if ($t['unread']): ?><span class="badge rounded-pill text-bg-danger align-self-center"><?= (int) $t['unread'] ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
