<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

$lang = 'en';
if(substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2) === 'de') {
	$lang = 'de';
}
session_start();

// Configuration
$uid_length = 6; // length of the random poll URLs

$site_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'];

$polls_directory = 'polls';
if (!file_exists($polls_directory)) {
	mkdir($polls_directory, 0755, true);
}

// Helper functions
function generatePollId($maxAttempts = 10) {
	global $uid_length, $polls_directory;
	$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
	
	for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
		$result = '';
		for ($i = 0; $i < $uid_length; $i++) {
			$result .= $chars[rand(0, strlen($chars) - 1)];
		}
		$path = $polls_directory . '/' . $result . '.json';
		if (!file_exists($path)) {
			return $result;
		}
	}
	return false; // failed to generate unique ID
}

function isValidPollId($pollId) {
	global $uid_length;
	$regex = '/^[a-z0-9]{' . $uid_length . ',}$/';
	return preg_match($regex, $pollId);
}

function getPollPath($pollId) {
	global $polls_directory;
	if (!isValidPollId($pollId)) {
		die('Invalid poll ID.'); // Fail hard for security
	}
	return $polls_directory . '/' . $pollId . '.json';
}

function loadPoll($pollId) {
	$path = getPollPath($pollId);
	if (!file_exists($path)) return null;
	$fp = fopen($path, 'r');
	if (!$fp) return null;

	if (flock($fp, LOCK_SH)) {
		$data = stream_get_contents($fp);
		flock($fp, LOCK_UN);
		fclose($fp);
		return json_decode($data, true);
	} else {
		fclose($fp);
		return null;
	}
}

function savePoll($pollId, $pollData) { // atomic vote recording
	$path = getPollPath($pollId);
	$fp = fopen($path, 'c+'); // 'c+' allows reading and writing, creates if not exists
	if (!$fp) return false;

	// Acquire an exclusive lock (blocking)
	if (flock($fp, LOCK_EX)) {
		// Rewind, truncate, write
		ftruncate($fp, 0);
		rewind($fp);
		fwrite($fp, json_encode($pollData, JSON_PRETTY_PRINT | JSON_HEX_QUOT | JSON_HEX_TAG));
		fflush($fp); // flush output before releasing lock
		flock($fp, LOCK_UN); // release lock
		fclose($fp);
		return true;
	} else {
		fclose($fp);
		return false;
	}
}

function sanitize($input) {
	// $output = htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
	$output = trim($input);
	return $output;
}

// Handle form submissions
$message = '';
$error = '';

// Set up defaults for form repopulation
$isEditing = false;
$editPollId = '';
$title = '';
$description = '';
$options = ['', '', '', ''];
$author = '';
$exclusive = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

	// (1) Handle EDIT START: show prefilled form after password check
	if (isset($_POST['start_edit'])) {
		$editPollId = sanitize($_POST['poll_id']);
		$edit_password = $_POST['edit_password'];
		$poll = loadPoll($editPollId);
		
                if ($poll && $poll['password'] && password_verify($edit_password, $poll['password'])) {
                        // Prefill form variables for edit
                        $title = $poll['title'];
                        $description = $poll['description'];
                        $options = $poll['options'];
                        $author = isset($poll['author']) ? $poll['author'] : '';
                        $exclusive = !empty($poll['exclusive']);
                        $isEditing = true;
                } else {
			$error = 'Incorrect password.';
			$isEditing = false;
		}
		
	}

        if (isset($_POST['create_poll']) || isset($_POST['edit_poll'])) {
                // Create new poll or save edits
                $title = sanitize($_POST['title']);
                $description = str_replace("\r", "\n", $_POST['description']);
                $description = preg_replace("/[\n]+/", "\n", $description); // unify and clean up newlines
                $description = sanitize($description);
                $author = isset($_POST['author']) ? sanitize($_POST['author']) : '';
                $passwordInput = isset($_POST['password']) ? trim($_POST['password']) : '';
                if (isset($_POST['create_poll'])) {
                        $exclusive = isset($_POST['exclusive_options']);
                }
                $options = array_filter(array_map('sanitize', $_POST['options']));
                $options = array_filter($options, function($opt) {
                        return strlen(trim($opt)) > 0; // prevent empty poll options
                });
                $options_lower = array_map('mb_strtolower', $options);
                $options = array_intersect_key($options, array_unique($options_lower));
                $options = array_values($options); // reindex for consistent indices

                $editPollId = isset($_POST['editing_poll_id']) ? sanitize($_POST['editing_poll_id']) : '';

                if (isset($_POST['edit_poll'])) {
                        $isEditing = true;
                }

                // Max length checks
                if (mb_strlen($title) > 100) {
                        $error = 'Poll title must be at most 100 characters.';
                }
                if (mb_strlen($description) > 3000) {
                        $error = 'Description must be at most 3000 characters.';
                }
                if (mb_strlen($author) > 80) {
                        $error = 'Author name must be at most 80 characters.';
                }
                foreach ($options as $option) {
                        if (mb_strlen($option) > 60) {
                                $error = 'Each option must be at most 60 characters.';
                                break;
                        }
                }

                if (empty($title) || count($options) < 1) {
                        $error = 'Title and at least one unique, non-empty option are required.';
                }

                if (empty($error)) {
                        $isEditSubmission = isset($_POST['edit_poll']) && !empty($editPollId);

                        if ($isEditSubmission) {
                                $pollId = $editPollId;
                                $existingPoll = loadPoll($pollId);

                                        if ($existingPoll) {
                                                $pollData = $existingPoll;
                                                if (!array_key_exists('exclusive', $pollData)) {
                                                        $pollData['exclusive'] = false;
                                                }
                                                $pollData['id'] = $pollId;
                                                $pollData['title'] = $title;
                                                $pollData['description'] = $description;
                                                $pollData['options'] = $options;
                                                $pollData['author'] = $author;

                                        if ($passwordInput !== '') {
                                                $pollData['password'] = password_hash($passwordInput, PASSWORD_DEFAULT);
                                        } elseif (!array_key_exists('password', $pollData)) {
                                                $pollData['password'] = null;
                                        }

                                        if (savePoll($pollId, $pollData)) {
                                                header("Location: /" . $pollId);
                                                exit;
                                        } else {
                                                $error = 'Failed to update poll. Please try again.';
                                        }
                                } else {
                                        $error = 'Poll not found. Unable to edit.';
                                }
                        } else {
                                $pollId = generatePollId();
                                if ($pollId === false) {
                                        $error = 'Failed to create poll. Please try again.';
                                } else {
                                        $pollData = [
                                                'id' => $pollId,
                                                'title' => $title,
                                                'description' => $description,
                                                'author' => $author,
                                                'password' => $passwordInput !== '' ? password_hash($passwordInput, PASSWORD_DEFAULT) : null,
                                                'options' => $options,
                                                'exclusive' => $exclusive,
                                                'votes' => [],
                                                'created_at' => date('Y-m-d H:i:s')
                                        ];

                                        if (savePoll($pollId, $pollData)) {
                                                header("Location: /" . $pollId);
                                                exit;
                                        } else {
                                                $error = 'Failed to create poll. Please try again.';
                                        }
                                }
                        }
                }
        } elseif (isset($_POST['vote'])) {
		// Submit vote
		$pollId = sanitize($_POST['poll_id']);
		if (!isValidPollId($pollId)) {
			$error = 'Invalid poll ID provided.';
		}
		$voterName = sanitize($_POST['voter_name']);
		if (mb_strlen($voterName) > 40) {
			$error = 'Your name must be at most 40 characters.';
		}
                $votes = $_POST['votes'] ?? [];
		
		if (empty($voterName)) {
			$error = 'Please enter your name.';
		} else {
                        $poll = loadPoll($pollId);
                        if ($poll) {
                                $voteSelections = is_array($votes) ? $votes : [$votes];
                                $voteSelections = array_map('intval', $voteSelections);
                                $voteSelections = array_values(array_filter($voteSelections, function($selection) use ($poll) {
                                        return array_key_exists($selection, $poll['options']);
                                }));

                                if (!empty($poll['exclusive'])) {
                                        $voteSelections = array_slice($voteSelections, 0, 1);
                                } else {
                                        $voteSelections = array_values(array_unique($voteSelections));
                                }

                                // Remove any existing vote from this voter
                                $poll['votes'] = array_filter($poll['votes'], function($vote) use ($voterName) {
                                        return $vote['name'] !== $voterName;
                                });

                                // Add new vote
                                $poll['votes'][] = [
                                        'name' => $voterName,
                                        'selections' => $voteSelections,
                                        'voted_at' => date('Y-m-d H:i:s')
                                ];
				
				if (savePoll($pollId, $poll)) {
					// Set cookie to remember user's vote
					$userData = isset($_COOKIE['poll_user_data']) ? json_decode($_COOKIE['poll_user_data'], true) : [];
					if (!is_array($userData)) $userData = [];
					
					$userData['name'] = $voterName;
					if (!isset($userData['voted_polls'])) $userData['voted_polls'] = [];
					if (!in_array($pollId, $userData['voted_polls'])) {
						$userData['voted_polls'][] = $pollId;
					}
					
					setcookie('poll_user_data', json_encode($userData), time() + (86400 * 30), '/'); // 30 days
					
					$message = 'Your vote has been recorded!';
				} else {
					$error = 'Failed to save your vote. Please try again.';
				}
			} else {
				$error = 'Poll not found.';
			}
		}
	}
}

// Get current poll if viewing one
$currentPoll = null;
$pollId = null;
$pollError = '';

// Get user data from cookie
$userData = isset($_COOKIE['poll_user_data']) ? json_decode($_COOKIE['poll_user_data'], true) : [];
if (!is_array($userData)) $userData = [];
$savedName = isset($userData['name']) ? $userData['name'] : '';
$votedPolls = isset($userData['voted_polls']) ? $userData['voted_polls'] : [];

// Parse URL - anything after the domain (except empty) is treated as a poll ID
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);
$pathParts = explode('/', trim($path, '/'));

if (!empty($pathParts[0]) && $pathParts[0] !== '') {
	$pollId = sanitize($pathParts[0]);
	$currentPoll = loadPoll($pollId);
	if (!$currentPoll) {
		$pollError = 'Poll not found. The poll ID "' . $pollId . '" does not exist or may have been deleted.';
		header("HTTP/1.0 404 Not Found");
	}
}

// Fallback for old URL format (if needed for backwards compatibility)
if (!$currentPoll && !$pollError && isset($_GET['poll'])) {
	$pollId = sanitize($_GET['poll']);
	$currentPoll = loadPoll($pollId);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">

	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo $currentPoll ? sanitize($currentPoll['title']) : 'Event Scheduler'; ?></title>

	<link rel="stylesheet" href="/main.css">
</head>
<body>
	<div class="container">

		<?php if ($message): ?>
			<div class="message success"><?php echo $message; ?></div>
		<?php endif; ?>

		<?php if ($error): ?>
			<div class="message error"><?php echo $error; ?></div>
		<?php endif; ?>

		<?php if ($pollError): ?>
			<div class="card">
				<div class="poll-info poll-error">
					<h1><a href="/">Poll not found</a></h1>

					<div class="meta"></div>

					<div class="desc">
						<p><?php echo $pollError; ?><br><br></p>
						<p><a href="/" class="btn">Create New Poll</a></p>
					</div>
				</div>
			</div>
		<?php elseif ($currentPoll && !$isEditing): ?>
			<!-- Display Poll -->
			<div class="card">
				<div class="poll-info">
					<h1><a href="<?php echo $site_url . '/' . $currentPoll['id']; ?>"><?php echo sanitize($currentPoll['title']); ?></a></h1>

                                        <div class="meta">
                                                <time title="Create Date" datetime="<?php echo $currentPoll['created_at']; ?>"><?php echo date('d.m.y', strtotime($currentPoll['created_at'])); ?></time>
                                                <?php if (!empty($currentPoll['author'])): ?>
                                                <span class="meta-author"><?php echo ($lang == 'de' ? 'von' : 'by'); ?> <?php echo sanitize($currentPoll['author']); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($currentPoll['exclusive'])): ?>
                                                <span class="meta-exclusive"><?= $lang == 'de' ? 'Nur eine Auswahl' : 'Single-choice'; ?></span>
                                                <?php endif; ?>
                                                <a href="<?php echo $site_url . '/' . $currentPoll['id']; ?>">id:<?php echo $currentPoll['id']; ?></a>
                                                <!-- Edit button if poll has a password -->
                                                <?php if ($currentPoll['password']): ?>
						<button class="btn btn-secondary" onclick="document.querySelector('#editDialog').showModal();">Edit&nbsp;Poll</button>
						<?php endif; ?>
						<a href="/">New&nbsp;Poll&nbsp;+</a>
					</div>

					<div class="desc">
						<?php if ($currentPoll['description']): ?>
						<p><?php echo implode('</p><p>', explode("\n", $currentPoll['description'])); ?></p>
						<?php endif; ?>
					</div>
				</div>

				<!-- Password dialog for editing -->
				<dialog id="editDialog">
					<form method="post" id="editDialogForm">
						<h2>Edit Poll</h2>
						
						<label for="edit_password">Enter password:</label>
						<input type="password" name="edit_password" id="edit_password" required autofocus>
						<input type="hidden" name="poll_id" value="<?php echo $currentPoll['id']; ?>">
						
						<button type="submit" name="start_edit" class="btn">Continue</button>
						<button type="button" class="btn" onclick="document.querySelector('#editDialog').close();">Cancel</button>
					</form>
				</dialog>

				<!--<div class="poll-url">
					<strong>Share this poll:</strong> <a href="<?php echo $site_url . '/' . $currentPoll['id']; ?>"><?= $currentPoll['id']; ?></a>
				</div>-->

				<?php 
				$hasVoted = in_array($currentPoll['id'], $votedPolls);
				
				// Calculate vote counts for each option (used in both voting form and results)
				$optionCounts = array_fill(0, count($currentPoll['options']), 0);
				foreach ($currentPoll['votes'] as $vote) {
					foreach ($vote['selections'] as $selection) {
						$optionCounts[$selection]++;
					}
				}
				
				if ($hasVoted): ?>
					<div class="already-voted">
						<h2><?= $lang == 'de' ? 'Du hast bereits abgestimmt' : 'You have already voted in this poll' ?></h2>
						<p><?= $lang == 'de' ? 'Danke für deine Teilnahme. Unten siehst du das aktuelle Ergebnis.' : 'Thank you for your participation! You can see the current results below.' ?></p>
					</div>
				<?php else: ?>
					<!-- Vote Form -->
					<h2><?= $lang == 'de' ? 'Stimme ab' : 'Cast your vote' ?></h2>
                                        <?php $isExclusivePoll = !empty($currentPoll['exclusive']); ?>
                                        <form class="vote-form" method="post">
                                                <input type="hidden" name="poll_id" value="<?php echo $currentPoll['id']; ?>">

                                                <div class="form-group">
                                                        <label for="voter_name"><?= $lang == 'de' ? 'Dein Name' : 'Your name' ?>:</label>
                                                        <input type="text" id="voter_name" name="voter_name" value="<?php echo sanitize($savedName); ?>" required>
                                                </div>

                                                <div class="form-group">
                                                        <label><?= $lang == 'de' ? 'Wähle deine bevorzugten Optionen' : 'Select your preferred options' ?>:</label>
                                                        <?php foreach ($currentPoll['options'] as $index => $option): ?>
                                                                <div class="vote-option">
                                                                        <label>
                                                                                <input type="<?php echo $isExclusivePoll ? 'radio' : 'checkbox'; ?>" name="<?php echo $isExclusivePoll ? 'votes' : 'votes[]'; ?>" value="<?php echo $index; ?>">
                                                                                <?php echo sanitize($option); ?>
                                                                        </label>
                                                                </div>
                                                        <?php endforeach; ?>
                                                        <?php if ($isExclusivePoll): ?>
                                                        <small class="exclusive-note"><?= $lang == 'de' ? 'Du kannst nur eine Option auswählen.' : 'You can choose only one option in this poll.' ?></small>
                                                        <?php endif; ?>
                                                </div>

                                                <button type="submit" name="vote" class="btn"><?= $lang == 'de' ? 'Abstimmen' : 'Submit Vote' ?></button>
                                        </form>
				<?php endif; ?>

				<!-- Results -->
				<div class="results-summary">
					<h2><?= $lang == 'de' ? 'Aktuelles Ergebnis' : 'Current Results' ?></h2>
					<?php if (!empty($currentPoll['votes'])): ?>
						<table class="voting-table">
							<thead>
								<tr>
									<th class="voter-name-header"><?= $lang == 'de' ? 'Name' : 'Name' ?></th>
									<?php foreach ($currentPoll['options'] as $index => $option): ?>
										<th class="option-header" data-option-index="<?php echo $index; ?>"><?php echo sanitize($option); ?></th>
									<?php endforeach; ?>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($currentPoll['votes'] as $voteIndex => $vote): ?>
									<tr class="vote-row" data-vote-index="<?php echo $voteIndex; ?>">
										<td class="voter-name-cell"><?php echo sanitize($vote['name']); ?></td>
										<?php foreach ($currentPoll['options'] as $index => $option): ?>
											<td class="vote-cell <?php echo in_array($index, $vote['selections']) ? 'vote-yes' : 'vote-no'; ?>" 
												data-option-index="<?php echo $index; ?>" 
												data-voter="<?php echo sanitize($vote['name']); ?>">
												<?php
													$text_yes = $lang == 'de' ? 'Ja' : 'Yes';
													$text_no = $lang == 'de' ? 'Nein' : 'No';
													echo in_array($index, $vote['selections']) ? $text_yes : $text_no;
												?>
											</td>
										<?php endforeach; ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
							<thead>
								<tr class="voting-table-footer">
									<th>Total</th>
									<?php
										$totalVotes = count($currentPoll['votes']);
										$maxVotes = max($optionCounts) ?: 1; // Prevent division by zero
										$highestVoteCount = max($optionCounts);
										
										foreach ($currentPoll['options'] as $index => $option) {
											$count = $optionCounts[$index];
											$percentage = $totalVotes > 0 ? ($count / $totalVotes) * 100 : 0;
											$isFavorite = $count > 0 && $count === $highestVoteCount;
											?>
											<th class="option-footer <?php echo $isFavorite ? 'vote-favorite' : ''; ?>">
												<span><?php echo $count . ' ' . ($count == 1 ? 'Vote' : 'Votes'); ?></span>
												<span><?php echo round($percentage, 1); ?>%</span>
											</th>
											<?php
										}
										?>
								</tr>
							</thead>
						</table>
					<?php else: ?>
						<p><?= $lang == 'de' ? 'Noch keine Stimmen. Stimm einfach als erste:r ab!' : 'No votes yet. Be the first to vote!' ?></p>
					<?php endif; ?>
				</div>
			</div>

		<?php else: ?>
			<!-- Create Poll Form -->
			<div class="card">
				<h1><?= $isEditing ? 'Edit' : 'Create a New' ?> Poll</h1>
				<form method="post">
					<?php if ($isEditing): ?>
					<input type="hidden" name="editing_poll_id" value="<?php echo $editPollId; ?>">
					<?php endif; ?>

					<div class="form-group">
						<label for="title">Poll Title:</label>
						<input type="text" id="title" name="title" required placeholder="e.g., Team Meeting" value="<?php echo $title; ?>">
					</div>

                                        <div class="form-group">
                                                <label for="description">Description (optional):</label>
                                                <textarea id="description" name="description" rows="3" placeholder="Additional details about the event …"><?php echo $description; ?></textarea>
                                        </div>

                                        <div class="form-group">
                                                <label for="author">Author (optional):</label>
                                                <input type="text" id="author" name="author" placeholder="e.g., Alex" value="<?php echo htmlspecialchars($author, ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>

                                        <div class="form-group">
                                                <label for="password">Password (optional):</label>
                                                <input type="text" id="password" name="password" placeholder="" value="<?= !empty($_POST['edit_password']) ? trim($_POST['edit_password']) : ''; ?>">
                                                <small>Setting a password may allow you to edit this poll later.</small>
                                        </div>

                                        <?php if (!$isEditing): ?>
                                        <div class="form-group checkbox-group">
                                                <label class="checkbox-label">
                                                        <input type="checkbox" name="exclusive_options" <?php echo $exclusive ? 'checked' : ''; ?>>
                                                        <span><?= $lang == 'de' ? 'Nur eine Option pro Teilnehmer erlauben' : 'Allow only one option per participant'; ?></span>
                                                </label>
                                                <small><?= $lang == 'de' ? 'Aktiviere dies, wenn jede:r nur eine Option wählen darf.' : 'Enable this to limit each voter to a single choice.' ?></small>
                                        </div>
                                        <?php endif; ?>

                                        <div class="form-group">
                                                <label>Options:</label>
                                                <div class="options-container">
							<?php
								// Always show at least 4 option fields, filled with old data or empty
								$max_options = max(4, count($options));
								for ($i = 0; $i < $max_options; $i++) {
									$opt_val = isset($options[$i]) ? $options[$i] : '';
									echo '<div class="option-input">
											<input type="text" name="options[]" placeholder="Option '.($i+1).'" value="'. $opt_val .'">
										  </div>';
								}
							?>
							<button type="button" id="add-option" class="btn btn-secondary btn-small">Add Option +</button>
						</div>
					</div>

                                        <button type="submit" name="<?php echo $isEditing ? 'edit_poll' : 'create_poll'; ?>" class="btn"><?php echo $isEditing ? 'Save Changes' : 'Create Poll'; ?></button>
                                </form>
                        </div>
		<?php endif; ?>
	</div>

	<script>
		function addOption () {
			const container = document.querySelector('.options-container');
			const addButton = container.querySelector('button');
			
			const newOption = document.createElement('div');
			const lastInput = container.querySelector('.option-input:last-of-type input');
			const lastIndex = parseInt(lastInput.getAttribute('placeholder').split(' ').at(-1));

			newOption.className = 'option-input';
			newOption.innerHTML = '<input type="text" name="options[]" placeholder="Option '+(lastIndex+1)+'">';

			container.insertBefore(newOption, addButton);
		}

		document.querySelector('#add-option')?.addEventListener('click', addOption);
	</script>
</body>
</html>
