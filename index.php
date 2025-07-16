<?php
session_start();

// Configuration
$uid_length = 6; // length of the random poll URLs

$polls_directory = 'polls';
if (!file_exists($polls_directory)) {
	mkdir($polls_directory, 0755, true);
}

// Helper functions
function generatePollId() {
	global $uid_length;
	$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
	$result = '';
	for ($i = 0; $i < $uid_length; $i++) {
		$result .= $chars[rand(0, strlen($chars) - 1)];
	}
	return $result;
}

function getPollPath($pollId) {
	global $polls_directory;
	return $polls_directory . '/' . $pollId . '.json';
}

function loadPoll($pollId) {
	$path = getPollPath($pollId);
	if (file_exists($path)) {
		return json_decode(file_get_contents($path), true);
	}
	return null;
}

function savePoll($pollId, $pollData) {
	$path = getPollPath($pollId);
	return file_put_contents($path, json_encode($pollData, JSON_PRETTY_PRINT));
}

function sanitize($input) {
	return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Handle form submissions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (isset($_POST['create_poll'])) {
		// Create new poll
		$title = sanitize($_POST['title']);
		$description = str_replace("\r", "\n", $_POST['description']);
		$description = preg_replace("/[\n]+/", "\n", $description); // unify and clean up newlines
		$description = sanitize($description);
		$password = !empty($_POST['password']) ? password_hash($_POST['password'], PASSWORD_DEFAULT) : null;
		$options = array_filter(array_map('sanitize', $_POST['options']));
		
		if (empty($title) || empty($options)) {
			$error = 'Title and at least one time option are required.';
		} else {
			$pollId = generatePollId();
			$pollData = [
				'id' => $pollId,
				'title' => $title,
				'description' => $description,
				'password' => $password,
				'options' => $options,
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
	} elseif (isset($_POST['vote'])) {
		// Submit vote
		$pollId = sanitize($_POST['poll_id']);
		$voterName = sanitize($_POST['voter_name']);
		$votes = $_POST['votes'] ?? [];
		
		if (empty($voterName)) {
			$error = 'Please enter your name.';
		} else {
			$poll = loadPoll($pollId);
			if ($poll) {
				// Remove any existing vote from this voter
				$poll['votes'] = array_filter($poll['votes'], function($vote) use ($voterName) {
					return $vote['name'] !== $voterName;
				});
				
				// Add new vote
				$poll['votes'][] = [
					'name' => $voterName,
					'selections' => $votes,
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
		<?php elseif ($currentPoll): ?>
			<!-- Display Poll -->
			<div class="card">
				<div class="poll-info">
					<h1><a href="<?php echo $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/' . $currentPoll['id']; ?>"><?php echo sanitize($currentPoll['title']); ?></a></h1>

					<div class="meta">
						<time title="Create Date" datetime="<?php echo $currentPoll['created_at']; ?>"><?php echo date('d.m.y', strtotime($currentPoll['created_at'])); ?></time>
						<a href="<?php echo $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/' . $currentPoll['id']; ?>">id:<?php echo $currentPoll['id']; ?></a>
						<a href="/">New Poll +</a>
					</div>

					<div class="desc">
						<?php if ($currentPoll['description']): ?>
						<p><?php echo implode('</p><p>', explode("\n", $currentPoll['description'])); ?></p>
						<?php endif; ?>
					</div>
				</div>

				<!--<div class="poll-url">
					<strong>Share this poll:</strong> <a href="<?php echo $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/' . $currentPoll['id']; ?>"><?= $currentPoll['id']; ?></a>
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
						<h2>You have already voted in this poll</h2>
						<p>Thank you for your participation! You can see the current results below.</p>
					</div>
				<?php else: ?>
					<!-- Vote Form -->
					<h2>Cast your vote</h2>
					<form class="vote-form" method="post">
						<input type="hidden" name="poll_id" value="<?php echo $currentPoll['id']; ?>">
						
						<div class="form-group">
							<label for="voter_name">Your name:</label>
							<input type="text" id="voter_name" name="voter_name" value="<?php echo sanitize($savedName); ?>" required>
						</div>

						<div class="form-group">
							<label>Select your preferred options:</label>
							<?php foreach ($currentPoll['options'] as $index => $option): ?>
								<div class="vote-option">
									<label>
										<input type="checkbox" name="votes[]" value="<?php echo $index; ?>">
										<?php echo sanitize($option); ?>
									</label>
								</div>
							<?php endforeach; ?>
						</div>

						<button type="submit" name="vote" class="btn">Submit Vote</button>
					</form>
				<?php endif; ?>

				<!-- Results -->
				<div class="results-summary">
					<h2>Current Results</h2>
					<?php if (!empty($currentPoll['votes'])): ?>
						<table class="voting-table">
							<thead>
								<tr>
									<th class="voter-name-header">Name</th>
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
												<?php echo in_array($index, $vote['selections']) ? 'Yes' : 'No'; ?>
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
												<span><?php echo $count . ' ' . ngettext("Vote", "Votes", $count); ?></span>
												<span><?php echo round($percentage, 1); ?>%</span>
											</th>
											<?php
										}
										?>
								</tr>
							</thead>
						</table>
					<?php else: ?>
						<p>No votes yet. Be the first to vote!</p>
					<?php endif; ?>
				</div>
			</div>

		<?php else: ?>
			<!-- Create Poll Form -->
			<div class="card">
				<h1>Create a New Poll</h1>
				<form method="post">
					<div class="form-group">
						<label for="title">Poll Title:</label>
						<input type="text" id="title" name="title" required placeholder="e.g., Team Meeting">
					</div>

					<div class="form-group">
						<label for="description">Description (optional):</label>
						<textarea id="description" name="description" rows="3" placeholder="Additional details about the event …"></textarea>
					</div>

					<div class="form-group">
						<label for="password">Password (optional):</label>
						<input type="text" id="password" name="password" placeholder="">
						<small>Setting a password may allow you to edit this poll later.</small>
					</div>

					<div class="form-group">
						<label>Options:</label>
						<div class="options-container">
							<div class="option-input">
								<input type="text" name="options[]" placeholder="e.g., Monday, Jan 15 at 2:00 PM" required>
							</div>
							<div class="option-input">
								<input type="text" name="options[]" placeholder="e.g., Tuesday, Jan 16 at 10:00 AM">
							</div>
							<div class="option-input">
								<input type="text" name="options[]" placeholder="e.g., Wednesday, Jan 17 at 3:00 PM">
							</div>
							<div class="option-input">
								<input type="text" name="options[]" placeholder="e.g., Thursday, Jan 18 at 1:00 PM">
							</div>
							<button type="button" class="btn btn-secondary btn-small" onclick="addOption()">Add Option +</button>
						</div>
					</div>

					<button type="submit" name="create_poll" class="btn">Create Poll</button>
				</form>
			</div>
		<?php endif; ?>
	</div>

	<script>
		function addOption() {
			const container = document.querySelector('.options-container');
			const addButton = container.querySelector('button');
			
			const newOption = document.createElement('div');
			newOption.className = 'option-input';
			newOption.innerHTML = '<input type="text" name="options[]" placeholder="e.g., Friday, Jan 19 at 11:00 AM">';
			
			container.insertBefore(newOption, addButton);
		}
	</script>
</body>
</html>
