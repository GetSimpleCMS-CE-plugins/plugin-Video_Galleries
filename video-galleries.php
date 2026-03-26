<?php
 
# Prevent direct access
if (!defined('IN_GS')) die('No direct access allowed');

# Get plugin ID
$thisfile = basename(__FILE__, ".php");

# Register plugin
register_plugin(
	$thisfile,
	'Video Galleries',
	'1.2',
	'CE Team',
	'https://getsimple-ce.ovh/donate',
	'Create Local or YouTube video galleries with shortcode or PHP function support',
	'plugins',
	'video_galleries_admin'
);

# Define constants
define('VIDEOGALLERIES_DB', GSDATAOTHERPATH . 'video_galleries.db');
define('VIDEOGALLERIES_PATH', GSDATAOTHERPATH . 'video_galleries/');

# Create folder if it doesn't exist
if (!file_exists(VIDEOGALLERIES_PATH)) {
	@mkdir(VIDEOGALLERIES_PATH, 0755, true);
}

# Initialize database
video_galleries_init_db();

# Add sidebar menu
add_action('plugins-sidebar', 'createSideMenu', array($thisfile, 'Video Galleries'));

# Add content filter for shortcodes
add_filter('content', 'video_galleries_process_shortcode');

/**
 * Initialize SQLite database
 */
function video_galleries_init_db() {
	if (!file_exists(VIDEOGALLERIES_DB)) {
		try {
			$db = new PDO('sqlite:' . VIDEOGALLERIES_DB);
			$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			
			// Create galleries table
			$db->exec("CREATE TABLE IF NOT EXISTS galleries (
				id TEXT PRIMARY KEY,
				name TEXT NOT NULL,
				columns INTEGER DEFAULT 3,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
			)");
			
			// Create videos table
			$db->exec("CREATE TABLE IF NOT EXISTS videos (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				gallery_id TEXT NOT NULL,
				title TEXT NOT NULL,
				url TEXT NOT NULL,
				thumb TEXT,
				sort_order INTEGER DEFAULT 0,
				FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
			)");
			
			// Create indexes
			$db->exec("CREATE INDEX IF NOT EXISTS idx_videos_gallery ON videos(gallery_id)");
			$db->exec("CREATE INDEX IF NOT EXISTS idx_videos_sort ON videos(gallery_id, sort_order)");
			
		} catch (PDOException $e) {
			error_log('Video Galleries DB Error: ' . $e->getMessage());
		}
	}
}

/**
 * Get database connection
 */
function video_galleries_get_db() {
	try {
		$db = new PDO('sqlite:' . VIDEOGALLERIES_DB);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		return $db;
	} catch (PDOException $e) {
		error_log('Video Galleries DB Error: ' . $e->getMessage());
		return null;
	}
}

/**
 * Extract YouTube video ID from URL or bare ID
 */
function video_galleries_youtube_id($input) {
	$input = trim($input);
	// Full URL patterns
	if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/', $input, $m)) {
		return $m[1];
	}
	// Bare ID (11 chars, alphanumeric + _ -)
	if (preg_match('/^[A-Za-z0-9_-]{11}$/', $input)) {
		return $input;
	}
	return false;
}

/**
 * Admin panel function
 */
function video_galleries_admin() {
	global $thisfile, $SITEURL;
	
	# Handle actions
	if (isset($_POST['save_gallery'])) {
		video_galleries_save();
	} elseif (isset($_GET['delete'])) {
		video_galleries_delete($_GET['delete']);
	}
	
	$galleries = video_galleries_get_all();
	$current_gallery = isset($_GET['edit']) ? $_GET['edit'] : '';
	$gallery_data = $current_gallery ? video_galleries_get($current_gallery) : null;
	
	# Include styles and scripts inline
	?>
	<script type="text/javascript">
function openFileBrowser(target) {
	var width = 800;
	var height = 600;
	var left = (screen.width/2)-(width/2);
	var top = (screen.height/2)-(height/2);
	
	var features = 'width=' + width + ',height=' + height + ',top=' + top + ',left=' + left + ',scrollbars=yes,resizable=yes';
	
	window.open('filebrowser.php?returnid=' + target + '&type=all&CKEditorFuncNum=0', 'filebrowser', features);
	
	return false;
}

function copyShortcode(galleryId) {
	var codeElement = document.getElementById('shortcode-' + galleryId);
	var text = codeElement.textContent;
	
	var textarea = document.createElement('textarea');
	textarea.value = text;
	textarea.style.position = 'fixed';
	textarea.style.opacity = '0';
	document.body.appendChild(textarea);
	
	textarea.select();
	document.execCommand('copy');
	document.body.removeChild(textarea);
	
	var button = event.target.closest('.vg-btn-copy');
	button.classList.add('copied');
	setTimeout(function() {
		button.classList.remove('copied');
	}, 1000);
}
	</script>
	
	<style>
	/* Main Container Styles */
	.video-galleries-container {
		max-width: 1200px;
		margin: 0 auto;
	}
	
	/* Card Styles */
	.vg-card {
		background: #fff;
		border: 1px solid #e0e0e0;
		border-radius: 8px;
		padding: 25px;
		margin-bottom: 25px;
		box-shadow: 0 2px 4px rgba(0,0,0,0.05);
	}
	
	.vg-card h4 {
		margin: 0 0 20px 0;
		padding-bottom: 15px;
		border-bottom: 2px solid #f0f0f0;
		color: #333;
		font-size: 18px;
		font-weight: 600;
	}
	
	/* Form Group Styles */
	.vg-form-group {
		margin-bottom: 25px;
	}
	
	.vg-form-group label {
		display: block;
		margin-bottom: 8px;
		font-weight: 600;
		color: #555;
		font-size: 14px;
	}
	
	.vg-form-group input[type="text"],
	.vg-form-group input[type="url"],
	.vg-form-group select {
		width: 100%;
		max-width: 600px;
		padding: 10px 12px;
		border: 1px solid #d0d0d0;
		border-radius: 5px;
		font-size: 14px;
		transition: all 0.3s;
		background: #fff;
	}
	
	.vg-form-group input:focus,
	.vg-form-group select:focus {
		outline: none;
		border-color: #4A90E2;
		box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
	}
	
	.vg-hint {
		display: block;
		margin-top: 6px;
		font-size: 13px;
		color: #777;
		font-style: italic;
	}
	
	/* Input Group with Button */
	.vg-input-group {
		display: flex;
		gap: 10px;
		align-items: flex-start;
		max-width: 600px;
	}
	
	.vg-input-group input {
		flex: 1;
	}
	
	.vg-btn-browse {
		padding: 10px 20px;
		background: #4A90E2;
		color: #fff;
		border: none;
		border-radius: 5px;
		cursor: pointer;
		font-size: 14px;
		font-weight: 500;
		transition: all 0.3s;
		white-space: nowrap;
	}
	
	.vg-btn-browse:hover {
		background: #357ABD;
		transform: translateY(-1px);
		box-shadow: 0 2px 8px rgba(74, 144, 226, 0.3);
	}
	
	/* Video Item Styles */
	.vg-video-item {
		background: #f9f9f9;
		border: 1px solid #e5e5e5;
		border-radius: 6px;
		padding: 20px;
		margin-bottom: 20px;
		position: relative;
		transition: all 0.3s;
	}
	
	.vg-video-item:hover {
		box-shadow: 0 2px 8px rgba(0,0,0,0.08);
	}
	
	.vg-video-item-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 15px;
		padding-bottom: 10px;
		border-bottom: 1px solid #e0e0e0;
	}
	
	.vg-video-number {
		font-weight: 600;
		color: #4A90E2;
		font-size: 16px;
	}
	
	/* Button Styles */
	.vg-btn {
		display: inline-block;
		padding: 10px 20px;
		border-radius: 5px;
		font-size: 14px;
		font-weight: 500;
		text-decoration: none;
		cursor: pointer;
		transition: all 0.3s;
		border: none;
		color:#fff;
	}
	
	.vg-btn-primary {
		background: #5cb85c;
		color: #fff;
	}
	
	.vg-btn-primary:hover {
		background: #4cae4c;
		transform: translateY(-1px);
		box-shadow: 0 2px 8px rgba(92, 184, 92, 0.3);
	}
	
	.vg-btn-secondary {
		background: #f0f0f0;
		color: #333;
	}
	
	.vg-btn-secondary:hover {
		background: #e0e0e0;
	}
	
	.vg-btn-danger {
		background: #d9534f;
		color: #fff;
		font-size: 13px;
		padding: 8px 16px;
	}
	
	.vg-btn-danger:hover {
		background: #c9302c;
	}
	
	.vg-btn-add {
		background: #4A90E2;
		color: #fff;
		padding: 12px 24px;
		font-size: 15px;
	}
	
	.vg-btn-add:hover {
		background: #357ABD;
	}
	
	/* Action Buttons */
	.vg-actions {
		display: flex;
		gap: 10px;
		margin-top: 30px;
		padding-top: 20px;
		border-top: 2px solid #f0f0f0;
	}
	
	/* Empty State */
	.vg-empty-state {
		text-align: center;
		padding: 60px 20px;
		color: #999;
	}
	
	.vg-empty-state svg {
		width: 80px;
		height: 80px;
		margin-bottom: 20px;
		opacity: 0.3;
	}
	
	/* Instructions Box */
	.vg-instructions {
		background: #f8f9fa;
		border-left: 4px solid #4A90E2;
		padding: 20px;
		margin-top: 30px;
		border-radius: 4px;
	}
	
	.vg-instructions h4 {
		margin-top: 0;
		color: #333;
		font-size: 16px;
	}
	
	.vg-instructions pre {
		background: #fff;
		padding: 12px;
		border-radius: 4px;
		border: 1px solid #e0e0e0;
		overflow-x: auto;
	}
	
	.vg-instructions p {
		margin: 10px 0;
	}
	
	/* Alert Messages */
	.vg-alert {
		padding: 15px 20px;
		border-radius: 5px;
		margin-bottom: 20px;
		font-size: 14px;
	}
	
	.vg-alert-success {
		background: #d4edda;
		border: 1px solid #c3e6cb;
		color: #155724;
	}
	
	/* Responsive */
	@media (max-width: 768px) {
		.vg-input-group {
			flex-direction: column;
		}
		
		.vg-btn-browse {
			width: 100%;
		}
		
		.vg-actions {
			flex-direction: column;
		}
		
		.vg-btn {
			width: 100%;
			text-align: center;
		}
	}
	
	/* Gallery Grid View */
.vg-galleries-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
	gap: 20px;
	margin-top: 20px;
}

/* Gallery Card */
.vg-gallery-card {
	background: #fff;
	border: 1px solid #e5e5e5;
	border-radius: 8px;
	padding: 20px;
	transition: all 0.3s ease;
	display: flex;
	flex-direction: column;
	gap: 15px;
}

.vg-gallery-card:hover {
	border-color: #4A90E2;
	box-shadow: 0 4px 12px rgba(74, 144, 226, 0.15);
	transform: translateY(-2px);
}

/* Gallery Header */
.vg-gallery-header {
	display: flex;
	gap: 15px;
	align-items: flex-start;
}

.vg-gallery-icon {
	width: 48px;
	height: 48px;
	background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
	border-radius: 8px;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}

.vg-gallery-icon svg {
	color: white;
}

.vg-gallery-info {
	flex: 1;
	min-width: 0;
}

.vg-gallery-name {
	margin: 0 0 8px 0;
	font-size: 18px;
	font-weight: 600;
	color: #333;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* Gallery Meta */
.vg-gallery-meta {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	font-size: 13px;
	color: #666;
}

.vg-gallery-meta span {
	display: flex;
	align-items: center;
	gap: 4px;
}

.vg-gallery-meta svg {
	opacity: 0.6;
	flex-shrink: 0;
}

.vg-gallery-id {
	font-family: 'Courier New', monospace;
	background: #f0f0f0;
	padding: 2px 8px;
	border-radius: 4px;
}

/* Shortcode Section */
.vg-gallery-shortcode {
	background: #f8f9fa;
	padding: 12px;
	border-radius: 6px;
}

.vg-gallery-shortcode label {
	display: block;
	font-size: 12px;
	font-weight: 600;
	color: #666;
	margin-bottom: 6px;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.vg-code-container {
	display: flex;
	gap: 8px;
	align-items: center;
	background: #fff;
	padding: 8px 12px;
	border-radius: 4px;
	border: 1px solid #e0e0e0;
}

.vg-code-container code {
	flex: 1;
	font-size: 13px;
	color: #d63384;
	background: transparent;
	padding: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.vg-btn-copy {
	background: transparent;
	border: none;
	padding: 4px;
	cursor: pointer;
	color: #666;
	transition: all 0.2s;
	border-radius: 4px;
	display: flex;
	align-items: center;
	justify-content: center;
}

.vg-btn-copy:hover {
	background: #e9ecef;
	color: #4A90E2;
}

.vg-btn-copy:active {
	transform: scale(0.95);
}

/* Gallery Actions */
.vg-gallery-actions {
	display: flex;
	gap: 8px;
	padding-top: 15px;
	border-top: 1px solid #f0f0f0;
}

.vg-btn-edit,
.vg-btn-delete {
	flex: 1;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	padding: 12px 16px;
	border-radius: 6px;
	font-size: 14px;
	font-weight: 600;
	text-decoration: none;
	transition: all 0.2s ease;
	border: 2px solid transparent;
	cursor: pointer;
}

.vg-btn-edit svg,
.vg-btn-delete svg {
	flex-shrink: 0;
}

.vg-btn-edit {
	background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
	color: white !important;
	box-shadow: 0 2px 4px rgba(102, 126, 234, 0.2);
	text-decoration:none !important;
}

.vg-btn-edit:hover {
	background: linear-gradient(135deg, #5568d3 0%, #653a8e 100%);
	transform: translateY(-2px);
	box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
		text-decoration:none !important;
}

.vg-btn-edit:active {
	transform: translateY(0);
	box-shadow: 0 2px 4px rgba(102, 126, 234, 0.2);
}

.vg-btn-delete {
	background: #dc3545;;
	color: #fff !important;
	border-color: #dc3545;
		text-decoration:none !important;
}

.vg-btn-delete:hover {
	background: #dc3545;
	color: white;
	border-color: #dc3545;
	transform: translateY(-2px);
	box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
		text-decoration:none !important;
}

.vg-btn-delete:active {
	transform: translateY(0);
	box-shadow: 0 2px 4px rgba(220, 53, 69, 0.2);
}

/* Responsive */
@media (max-width: 768px) {
	.vg-galleries-grid {
		grid-template-columns: 1fr;
	}
	
	.vg-gallery-actions {
		flex-direction: column;
		gap: 10px;
	}
	
	.vg-btn-edit,
	.vg-btn-delete {
		width: 100%;
	}
	
	.vg-instructions-grid {
		grid-template-columns: 1fr;
	}
}

/* Instructions Grid */
.vg-instructions-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
	gap: 20px;
	margin-top: 15px;
}

.vg-instruction-item h5 {
	margin: 0 0 10px 0;
	color: #4A90E2;
	font-size: 16px;
	font-weight: 600;
}

.vg-instruction-item p {
	margin: 0 0 10px 0;
	color: #666;
	font-size: 14px;
}

/* Responsive */
@media (max-width: 768px) {
	.vg-galleries-grid {
		grid-template-columns: 1fr;
	}
	
	.vg-gallery-actions {
		flex-direction: column;
	}
	
	.vg-instructions-grid {
		grid-template-columns: 1fr;
	}
}

/* Copy Animation */
@keyframes copied {
	0% { transform: scale(1); }
	50% { transform: scale(1.2); }
	100% { transform: scale(1); }
}

.vg-btn-copy.copied {
	animation: copied 0.3s ease;
	color: #28a745;
}
	</style>
	
	<div class="video-galleries-container">
		<h3 class="floated">Video Galleries</h3>
		<div class="edit-nav clearfix">
			<?php if (!$current_gallery): ?>
			<a href="?id=video-galleries&edit=new" class="btn vg-btn-add">+ Add New Gallery</a>
			<?php else: ?>
			<a href="?id=video-galleries" class="btn vg-btn-secondary">← Back to List</a>
			<?php endif; ?>
		</div>
		
  <?php if (!$current_gallery): ?>
	<!-- Gallery list -->
	<div class="vg-card">
		<?php if (empty($galleries)): ?>
		<div class="vg-empty-state">
			<svg fill="currentColor" viewBox="0 0 20 20">
				<path d="M2 6a2 2 0 012-2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6zM14.553 7.106A1 1 0 0014 8v4a1 1 0 00.553.894l2 1A1 1 0 0018 13V7a1 1 0 00-1.447-.894l-2 1z"></path>
			</svg>
			<p><strong>No galleries yet</strong></p>
			<p>Create your first video gallery to get started!</p>
		</div>
		<?php else: ?>
		<div class="vg-galleries-grid">
			<?php foreach ($galleries as $id => $gallery): ?>
			<div class="vg-gallery-card">
				<div class="vg-gallery-header">
					<div class="vg-gallery-icon">
						<svg fill="currentColor" viewBox="0 0 20 20" width="32" height="32">
							<path d="M2 6a2 2 0 012-2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6zM14.553 7.106A1 1 0 0014 8v4a1 1 0 00.553.894l2 1A1 1 0 0018 13V7a1 1 0 00-1.447-.894l-2 1z"></path>
						</svg>
					</div>
					<div class="vg-gallery-info">
						<h4 class="vg-gallery-name"><?php echo htmlspecialchars($gallery['name']); ?></h4>
						<div class="vg-gallery-meta">
							<span class="vg-gallery-id">
								<svg fill="currentColor" viewBox="0 0 20 20" width="14" height="14">
									<path fill-rule="evenodd" d="M12.586 4.586a2 2 0 112.828 2.828l-3 3a2 2 0 01-2.828 0 1 1 0 00-1.414 1.414 4 4 0 005.656 0l3-3a4 4 0 00-5.656-5.656l-1.5 1.5a1 1 0 101.414 1.414l1.5-1.5zm-5 5a2 2 0 012.828 0 1 1 0 101.414-1.414 4 4 0 00-5.656 0l-3 3a4 4 0 105.656 5.656l1.5-1.5a1 1 0 10-1.414-1.414l-1.5 1.5a2 2 0 11-2.828-2.828l3-3z"></path>
								</svg>
								ID: <?php echo htmlspecialchars($id); ?>
							</span>
							<span class="vg-gallery-videos">
								<svg fill="currentColor" viewBox="0 0 20 20" width="14" height="14">
									<path d="M2 6a2 2 0 012-2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6zM14.553 7.106A1 1 0 0014 8v4a1 1 0 00.553.894l2 1A1 1 0 0018 13V7a1 1 0 00-1.447-.894l-2 1z"></path>
								</svg>
								<?php echo count($gallery['videos']); ?> video<?php echo count($gallery['videos']) != 1 ? 's' : ''; ?>
							</span>
							<span class="vg-gallery-columns">
								<svg fill="currentColor" viewBox="0 0 20 20" width="14" height="14">
									<path fill-rule="evenodd" d="M3 4a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H4a1 1 0 01-1-1V4zm6 0a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V4zM3 12a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H4a1 1 0 01-1-1v-4zm6 0a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"></path>
								</svg>
								<?php echo $gallery['columns']; ?> columns
							</span>
						</div>
					</div>
				</div>
				
				<div class="vg-gallery-shortcode">
					<label>Shortcode:</label>
					<div class="vg-code-container">
						<code id="shortcode-<?php echo htmlspecialchars($id); ?>">(% video-gallery id="<?php echo htmlspecialchars($id); ?>" %)</code>
						<button type="button" class="vg-btn-copy" onclick="copyShortcode('<?php echo htmlspecialchars($id); ?>')" title="Copy to clipboard">
							<svg fill="currentColor" viewBox="0 0 20 20" width="16" height="16">
								<path d="M8 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"></path>
								<path d="M6 3a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2 3 3 0 01-3 3H9a3 3 0 01-3-3z"></path>
							</svg>
						</button>
					</div>
				</div>
				
				<div class="vg-gallery-actions">
					<a href="?id=video-galleries&edit=<?php echo urlencode($id); ?>" class="vg-btn vg-btn-edit">
						<svg fill="currentColor" viewBox="0 0 20 20" width="16" height="16">
							<path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"></path>
						</svg>
						Edit
					</a>
					<a href="?id=video-galleries&delete=<?php echo urlencode($id); ?>" 
					   onclick="return confirm('Are you sure you want to delete this gallery?');" 
					   class="vg-btn vg-btn-delete">
						<svg fill="currentColor" viewBox="0 0 20 20" width="16" height="16">
							<path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z"></path>
						</svg>
						Delete
					</a>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
	
	<div class="vg-instructions">
		<h4>📝 Usage Instructions</h4>
		<div class="vg-instructions-grid">
			<div class="vg-instruction-item">
				<h5>In Page Content</h5>
				<p>Use the shortcode in your page editor:</p>
				<pre>(% video-gallery id="your-gallery-id" %)</pre>
			</div>
			<div class="vg-instruction-item">
				<h5>In Template PHP</h5>
				<p>Call the function in your theme files:</p>
				<pre>&lt;?php display_video_gallery('your-gallery-id'); ?&gt;</pre>
			</div>
		</div>
	</div>

	  		<p id="donate" style="margin:20px 0;padding:15px;border:solid 1px #ddd;background:#fafafa;border-radius:5px;">Made with 
				<span class="credit-icon">❤️</span> especially for "
				<b><?php global $USR; echo $USR;?></b>". Is this plugin useful to you?
	
				<span class="w3-btn w3-khaki w3-border w3-border-red w3-round-xlarge">
					<a href="https://getsimple-ce.ovh/donate" target="_blank" class="donateButton">
						<b>Buy Us A Coffee </b>
						<svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" fill-opacity="0" d="M17 14v4c0 1.66 -1.34 3 -3 3h-6c-1.66 0 -3 -1.34 -3 -3v-4Z"><animate fill="freeze" attributeName="fill-opacity" begin="0.8s" dur="0.5s" values="0;1"/></path><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"><path stroke-dasharray="48" stroke-dashoffset="48" d="M17 9v9c0 1.66 -1.34 3 -3 3h-6c-1.66 0 -3 -1.34 -3 -3v-9Z"><animate fill="freeze" attributeName="stroke-dashoffset" dur="0.6s" values="48;0"/></path><path stroke-dasharray="14" stroke-dashoffset="14" d="M17 9h3c0.55 0 1 0.45 1 1v3c0 0.55 -0.45 1 -1 1h-3"><animate fill="freeze" attributeName="stroke-dashoffset" begin="0.6s" dur="0.2s" values="14;0"/></path><mask id="lineMdCoffeeHalfEmptyFilledLoop0"><path stroke="#fff" d="M8 0c0 2-2 2-2 4s2 2 2 4-2 2-2 4 2 2 2 4M12 0c0 2-2 2-2 4s2 2 2 4-2 2-2 4 2 2 2 4M16 0c0 2-2 2-2 4s2 2 2 4-2 2-2 4 2 2 2 4"><animateMotion calcMode="linear" dur="3s" path="M0 0v-8" repeatCount="indefinite"/></path></mask><rect width="24" height="0" y="7" fill="currentColor" mask="url(#lineMdCoffeeHalfEmptyFilledLoop0)"><animate fill="freeze" attributeName="y" begin="0.8s" dur="0.6s" values="7;2"/><animate fill="freeze" attributeName="height" begin="0.8s" dur="0.6s" values="0;5"/></rect></g></svg>
					</a>
				</span>
			</p>

		<?php else: ?>
			<!-- Edit/Add form -->
			<form method="post" action="?id=video-galleries">
				<input type="hidden" name="gallery_id" value="<?php echo htmlspecialchars($current_gallery == 'new' ? '' : $current_gallery); ?>">
				
				<div class="vg-card">
					<h4>Gallery Settings</h4>
					
					<div class="vg-form-group">
						<label for="gallery_name">Gallery Name *</label>
						<input type="text" id="gallery_name" name="gallery_name" 
							   value="<?php echo $gallery_data ? htmlspecialchars($gallery_data['name']) : ''; ?>" 
							   placeholder="e.g., Product Videos" required>
						<span class="vg-hint">A descriptive name for your video gallery</span>
					</div>
					
					<div class="vg-form-group">
						<label for="gallery_slug">Gallery ID (slug) *</label>
						<input type="text" id="gallery_slug" name="gallery_slug" 
							   value="<?php echo $current_gallery != 'new' ? htmlspecialchars($current_gallery) : ''; ?>" 
							   pattern="[a-z0-9-]+" 
							   placeholder="e.g., product-videos"
							   <?php echo $current_gallery != 'new' ? 'readonly' : ''; ?> 
							   required>
						<span class="vg-hint">Only lowercase letters, numbers and hyphens. This will be used in the shortcode.</span>
					</div>
					
					<div class="vg-form-group">
						<label for="columns">Grid Columns</label>
						<select name="columns" id="columns">
							<option value="1" <?php echo ($gallery_data && $gallery_data['columns'] == '1') ? 'selected' : ''; ?>>1 column (centered, max-width)</option>
							<option value="2" <?php echo ($gallery_data && $gallery_data['columns'] == '2') ? 'selected' : ''; ?>>2 columns</option>
							<option value="3" <?php echo (!$gallery_data || $gallery_data['columns'] == '3') ? 'selected' : ''; ?>>3 columns (recommended)</option>
							<option value="4" <?php echo ($gallery_data && $gallery_data['columns'] == '4') ? 'selected' : ''; ?>>4 columns</option>
						</select>
						<span class="vg-hint">Number of video columns in the gallery grid</span>
					</div>
				</div>
				
				<div class="vg-card">
					<h4>Videos</h4>
					<div id="videos-container">
						<?php 
						if ($gallery_data && !empty($gallery_data['videos'])) {
							foreach ($gallery_data['videos'] as $idx => $video) {
								video_galleries_render_video_field($idx, $video);
							}
						} else {
							video_galleries_render_video_field(0);
						}
						?>
					</div>
					
					<button type="button" onclick="addVideoField()" class="vg-btn vg-btn-add">+ Add Another Video</button>
				</div>
				
				<div class="vg-actions">
					<input type="submit" name="save_gallery" value="Save Gallery" class="vg-btn vg-btn-primary">
					<a href="?id=video-galleries" class="vg-btn vg-btn-secondary">Cancel</a>
				</div>
			</form>
			
			<script type="text/javascript">
			var videoIndex = <?php echo $gallery_data ? count($gallery_data['videos']) : 1; ?>;
			
			function vgToggleType(sel, idx) {
				var isYT = sel.value === 'youtube';
				var localEl = document.querySelector('.vg-local-fields-' + idx);
				var ytEl	= document.querySelector('.vg-youtube-fields-' + idx);
				if (localEl) localEl.style.display = isYT ? 'none' : '';
				if (ytEl)	ytEl.style.display	= isYT ? '' : 'none';
			}

			function addVideoField() {
				var container = document.getElementById('videos-container');
				var div = document.createElement('div');
				div.className = 'vg-video-item';
				var i = videoIndex;
				var videoNum = i + 1;

				div.innerHTML =
					'<div class="vg-video-item-header">' +
						'<span class="vg-video-number">Video ' + videoNum + '</span>' +
						'<button type="button" onclick="this.parentElement.parentElement.remove()" class="vg-btn vg-btn-danger">Remove</button>' +
					'</div>' +
					'<div class="vg-form-group">' +
						'<label>Video Title *</label>' +
						'<input type="text" name="video_title[]" placeholder="e.g., Introduction Video" required>' +
					'</div>' +
					'<div class="vg-form-group">' +
						'<label>Video Type</label>' +
						'<select name="video_type[]" onchange="vgToggleType(this,' + i + ')">' +
							'<option value="local">Local / Direct URL (MP4, WebM…)</option>' +
							'<option value="youtube">YouTube</option>' +
						'</select>' +
					'</div>' +
					'<div class="vg-form-group vg-local-fields-' + i + '">' +
						'<label>Video File URL *</label>' +
						'<div class="vg-input-group">' +
							'<input type="url" name="video_url[]" id="video_url_' + i + '" placeholder="https://example.com/video.mp4">' +
							'<button type="button" onclick="openFileBrowser(\'video_url_' + i + '\')" class="vg-btn-browse">Browse Files</button>' +
						'</div>' +
						'<span class="vg-hint">Supported formats: MP4, WebM, OGG</span>' +
					'</div>' +
					'<div class="vg-form-group vg-youtube-fields-' + i + '" style="display:none">' +
						'<label>YouTube URL or Video ID</label>' +
						'<input type="text" name="video_yt[]" id="video_yt_' + i + '" placeholder="https://www.youtube.com/watch?v=… or just the video ID">' +
						'<span class="vg-hint">Paste the full YouTube URL or just the 11-character video ID</span>' +
					'</div>' +
					'<div class="vg-form-group">' +
						'<label>Thumbnail URL (optional)</label>' +
						'<div class="vg-input-group">' +
							'<input type="url" name="video_thumb[]" id="video_thumb_' + i + '" placeholder="https://example.com/thumbnail.jpg">' +
							'<button type="button" onclick="openFileBrowser(\'video_thumb_' + i + '\')" class="vg-btn-browse">Browse Files</button>' +
						'</div>' +
						'<span class="vg-hint">Optional. For YouTube videos, the YouTube thumbnail is used automatically if left empty.</span>' +
					'</div>';

				container.appendChild(div);
				videoIndex++;
			}
			</script>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Render single video field
 */
function video_galleries_render_video_field($index, $data = null) {
	$is_youtube = false;
	$yt_id = '';
	if ($data && !empty($data['url'])) {
		$extracted = video_galleries_youtube_id($data['url']);
		if ($extracted) {
			$is_youtube = true;
			$yt_id = $extracted;
		}
	}
	$video_type = $is_youtube ? 'youtube' : 'local';
	?>
	<div class="vg-video-item">
		<div class="vg-video-item-header">
			<span class="vg-video-number">Video <?php echo $index + 1; ?></span>
			<?php if ($index > 0): ?>
			<button type="button" onclick="this.parentElement.parentElement.remove()" class="vg-btn vg-btn-danger">Remove</button>
			<?php endif; ?>
		</div>

		<div class="vg-form-group">
			<label>Video Title *</label>
			<input type="text" name="video_title[]"
				   value="<?php echo $data ? htmlspecialchars($data['title']) : ''; ?>"
				   placeholder="e.g., Introduction Video" required>
		</div>

		<div class="vg-form-group">
			<label>Video Type</label>
			<select name="video_type[]" onchange="vgToggleType(this, <?php echo $index; ?>)">
				<option value="local" <?php echo $video_type == 'local' ? 'selected' : ''; ?>>Local / Direct URL (MP4, WebM…)</option>
				<option value="youtube" <?php echo $video_type == 'youtube' ? 'selected' : ''; ?>>YouTube</option>
			</select>
		</div>

		<div class="vg-form-group vg-local-fields-<?php echo $index; ?>" <?php echo $is_youtube ? 'style="display:none"' : ''; ?>>
			<label>Video File URL *</label>
			<div class="vg-input-group">
				<input type="url" name="video_url[]" id="video_url_<?php echo $index; ?>"
					   value="<?php echo ($data && !$is_youtube) ? htmlspecialchars($data['url']) : ''; ?>"
					   placeholder="https://example.com/video.mp4">
				<button type="button" onclick="openFileBrowser('video_url_<?php echo $index; ?>')" class="vg-btn-browse">Browse Files</button>
			</div>
			<span class="vg-hint">Supported formats: MP4, WebM, OGG</span>
		</div>

		<div class="vg-form-group vg-youtube-fields-<?php echo $index; ?>" <?php echo !$is_youtube ? 'style="display:none"' : ''; ?>>
			<label>YouTube URL or Video ID</label>
			<input type="text" name="video_yt[]" id="video_yt_<?php echo $index; ?>"
				   value="<?php echo $is_youtube ? htmlspecialchars($yt_id) : ''; ?>"
				   placeholder="https://www.youtube.com/watch?v=… or just the video ID">
			<span class="vg-hint">Paste the full YouTube URL or just the 11-character video ID</span>
		</div>

		<div class="vg-form-group">
			<label>Thumbnail URL (optional)</label>
			<div class="vg-input-group">
				<input type="url" name="video_thumb[]" id="video_thumb_<?php echo $index; ?>"
					   value="<?php echo $data ? htmlspecialchars($data['thumb']) : ''; ?>"
					   placeholder="https://example.com/thumbnail.jpg">
				<button type="button" onclick="openFileBrowser('video_thumb_<?php echo $index; ?>')" class="vg-btn-browse">Browse Files</button>
			</div>
			<span class="vg-hint">Optional. For YouTube videos, the YouTube thumbnail is used automatically if left empty.</span>
		</div>
	</div>
	<?php
}

/**
 * Save gallery
 */
function video_galleries_save() {
	global $thisfile;
	
	$db = video_galleries_get_db();
	if (!$db) return;
	
	try {
		$db->beginTransaction();
		
		$gallery_id = isset($_POST['gallery_id']) && $_POST['gallery_id'] != '' 
			? $_POST['gallery_id'] 
			: clean_url($_POST['gallery_slug']);
		
		// Check if gallery exists
		$stmt = $db->prepare("SELECT id FROM galleries WHERE id = ?");
		$stmt->execute([$gallery_id]);
		$exists = $stmt->fetch();
		
		if ($exists) {
			// Update existing gallery
			$stmt = $db->prepare("UPDATE galleries SET name = ?, columns = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
			$stmt->execute([
				strip_tags($_POST['gallery_name']),
				intval($_POST['columns']),
				$gallery_id
			]);
			
			// Delete old videos
			$stmt = $db->prepare("DELETE FROM videos WHERE gallery_id = ?");
			$stmt->execute([$gallery_id]);
		} else {
			// Insert new gallery
			$stmt = $db->prepare("INSERT INTO galleries (id, name, columns) VALUES (?, ?, ?)");
			$stmt->execute([
				$gallery_id,
				strip_tags($_POST['gallery_name']),
				intval($_POST['columns'])
			]);
		}
		
		// Insert videos
		if (isset($_POST['video_title']) && is_array($_POST['video_title'])) {
			$stmt = $db->prepare("INSERT INTO videos (gallery_id, title, url, thumb, sort_order) VALUES (?, ?, ?, ?, ?)");
			
			foreach ($_POST['video_title'] as $idx => $title) {
				if (empty($title)) continue;

				$vtype = isset($_POST['video_type'][$idx]) ? $_POST['video_type'][$idx] : 'local';
				$url   = '';
				$thumb = isset($_POST['video_thumb'][$idx]) ? strip_tags(trim($_POST['video_thumb'][$idx])) : '';

				if ($vtype === 'youtube') {
					$raw_yt = isset($_POST['video_yt'][$idx]) ? trim($_POST['video_yt'][$idx]) : '';
					$yt_id  = video_galleries_youtube_id($raw_yt);
					if ($yt_id) {
						$url = 'youtube:' . $yt_id;
						if (empty($thumb)) {
							$thumb = 'https://img.youtube.com/vi/' . $yt_id . '/hqdefault.jpg';
						}
					}
				} else {
					$url = isset($_POST['video_url'][$idx]) ? strip_tags(trim($_POST['video_url'][$idx])) : '';
				}

				if (!empty($url)) {
					$stmt->execute([
						$gallery_id,
						strip_tags($title),
						$url,
						$thumb,
						$idx
					]);
				}
			}
		}
		
		$db->commit();
		
		echo '<div class="vg-alert vg-alert-success">✓ Gallery saved successfully!</div>';
		echo '<script>setTimeout(function(){ window.location.href="?id=video-galleries"; }, 1500);</script>';
		
	} catch (PDOException $e) {
		$db->rollBack();
		echo '<div class="vg-alert vg-alert-danger">✗ Error saving gallery: ' . htmlspecialchars($e->getMessage()) . '</div>';
	}
}

/**
 * Delete gallery
 */
function video_galleries_delete($id) {
	global $thisfile;
	
	$db = video_galleries_get_db();
	if (!$db) return;
	
	try {
		$db->beginTransaction();
		
		// Delete videos first
		$stmt = $db->prepare("DELETE FROM videos WHERE gallery_id = ?");
		$stmt->execute([$id]);
		
		// Delete gallery
		$stmt = $db->prepare("DELETE FROM galleries WHERE id = ?");
		$stmt->execute([$id]);
		
		$db->commit();
		
		echo '<div class="vg-alert vg-alert-success">✓ Gallery deleted successfully!</div>';
		echo '<script>setTimeout(function(){ window.location.href="?id=' . $thisfile . '"; }, 1000);</script>';
		
	} catch (PDOException $e) {
		$db->rollBack();
		error_log('Video Galleries Delete Error: ' . $e->getMessage());
	}
}

/**
 * Get all galleries
 */
function video_galleries_get_all() {
	$db = video_galleries_get_db();
	if (!$db) return [];
	
	try {
		$stmt = $db->query("SELECT * FROM galleries ORDER BY updated_at DESC");
		$galleries = [];
		
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$id = $row['id'];
			
			// Get videos for this gallery
			$videoStmt = $db->prepare("SELECT * FROM videos WHERE gallery_id = ? ORDER BY sort_order ASC");
			$videoStmt->execute([$id]);
			
			$videos = [];
			while ($video = $videoStmt->fetch(PDO::FETCH_ASSOC)) {
				$videos[] = [
					'title' => $video['title'],
					'url' => $video['url'],
					'thumb' => $video['thumb']
				];
			}
			
			$galleries[$id] = [
				'name' => $row['name'],
				'columns' => $row['columns'],
				'videos' => $videos
			];
		}
		
		return $galleries;
		
	} catch (PDOException $e) {
		error_log('Video Galleries Get All Error: ' . $e->getMessage());
		return [];
	}
}

/**
 * Get single gallery
 */
function video_galleries_get($id) {
	$db = video_galleries_get_db();
	if (!$db) return null;
	
	try {
		$stmt = $db->prepare("SELECT * FROM galleries WHERE id = ?");
		$stmt->execute([$id]);
		$gallery = $stmt->fetch(PDO::FETCH_ASSOC);
		
		if (!$gallery) return null;
		
		// Get videos
		$videoStmt = $db->prepare("SELECT * FROM videos WHERE gallery_id = ? ORDER BY sort_order ASC");
		$videoStmt->execute([$id]);
		
		$videos = [];
		while ($video = $videoStmt->fetch(PDO::FETCH_ASSOC)) {
			$videos[] = [
				'title' => $video['title'],
				'url' => $video['url'],
				'thumb' => $video['thumb']
			];
		}
		
		return [
			'name' => $gallery['name'],
			'columns' => $gallery['columns'],
			'videos' => $videos
		];
		
	} catch (PDOException $e) {
		error_log('Video Galleries Get Error: ' . $e->getMessage());
		return null;
	}
}

/**
 * Process shortcode in content
 */
function video_galleries_process_shortcode($content) {
	$pattern = '/\(%\s*video-gallery\s+id=["\']([^"\']+)["\']\s*%\)/';
	$content = preg_replace_callback($pattern, function($matches) {
		return video_galleries_display($matches[1]);
	}, $content);
	
	return $content;
}

/**
 * PHP function to display gallery
 */
function display_video_gallery($id) {
	echo video_galleries_display($id);
}

/**
 * Generate gallery HTML
 */
function video_galleries_display($id) {
	$gallery = video_galleries_get($id);
	
	if (!$gallery) {
		return '<!-- Video gallery "' . htmlspecialchars($id) . '" not found -->';
	}
	
	if (empty($gallery['videos'])) {
		return '<!-- Gallery "' . htmlspecialchars($gallery['name']) . '" contains no videos -->';
	}
	
	$columns = isset($gallery['columns']) ? intval($gallery['columns']) : 3;
	$galleryId = htmlspecialchars($id);
	
	ob_start();
	?>
	<div class="video-gallery video-gallery-<?php echo $galleryId; ?>" data-columns="<?php echo $columns; ?>">
		<?php foreach ($gallery['videos'] as $idx => $video):
			$is_yt = (strpos($video['url'], 'youtube:') === 0);
			$yt_id = $is_yt ? substr($video['url'], 8) : '';
			$thumb = !empty($video['thumb']) ? $video['thumb'] : '';
			if ($is_yt && empty($thumb)) {
				$thumb = 'https://img.youtube.com/vi/' . htmlspecialchars($yt_id) . '/hqdefault.jpg';
			}
		?>
		<div class="video-item" onclick="openVideoLightbox<?php echo $galleryId; ?>(<?php echo $idx; ?>)">
			<div class="video-wrapper">
				<?php if (!empty($thumb)): ?>
					<img src="<?php echo htmlspecialchars($thumb); ?>" alt="<?php echo htmlspecialchars($video['title']); ?>">
				<?php elseif (!$is_yt): ?>
					<video class="video-preview" preload="metadata" muted>
						<source src="<?php echo htmlspecialchars($video['url']); ?>#t=0.5" type="video/<?php echo pathinfo($video['url'], PATHINFO_EXTENSION); ?>">
					</video>
				<?php endif; ?>
				<div class="play-button">
					<svg width="60" height="60" viewBox="0 0 60 60">
						<circle cx="30" cy="30" r="30" fill="rgba(255,255,255,0.9)"/>
						<path d="M25 20 L25 40 L40 30 Z" fill="#333"/>
					</svg>
				</div>
			</div>
			<h4 class="video-title"><?php echo htmlspecialchars($video['title']); ?></h4>
		</div>
		<?php endforeach; ?>
	</div>
	
	<!-- Lightbox Modal -->
	<div id="videoLightbox<?php echo $galleryId; ?>" class="video-lightbox" onclick="closeVideoLightbox<?php echo $galleryId; ?>(event)">
		<div class="lightbox-content">
			<button class="lightbox-close" onclick="closeVideoLightbox<?php echo $galleryId; ?>(event)">&times;</button>
			<div class="lightbox-video-container">
				<video id="lightboxVideo<?php echo $galleryId; ?>" controls style="display:none;width:100%;height:100%;">
					<source id="lightboxSource<?php echo $galleryId; ?>" src="" type="video/mp4">
					Your browser does not support the video tag.
				</video>
				<iframe id="lightboxIframe<?php echo $galleryId; ?>"
					src=""
					frameborder="0"
					allow="autoplay; encrypted-media; picture-in-picture"
					allowfullscreen
					style="display:none;width:100%;height:100%;border:0;"></iframe>
			</div>
			<h3 id="lightboxTitle<?php echo $galleryId; ?>" class="lightbox-title"></h3>
		</div>
	</div>
	
	<style>
	/* Gallery Grid */
	.video-gallery {
		display: grid;
		gap: 20px;
		margin: 20px 0;
	}
	.video-gallery[data-columns="1"] { grid-template-columns: 1fr; max-width: 800px; margin-left: auto; margin-right: auto; }
	.video-gallery[data-columns="2"] { grid-template-columns: repeat(2, 1fr); }
	.video-gallery[data-columns="3"] { grid-template-columns: repeat(3, 1fr); }
	.video-gallery[data-columns="4"] { grid-template-columns: repeat(4, 1fr); }
	
	/* Video Item */
	.video-gallery .video-item {
		background: #f5f5f5;
		border-radius: 8px;
		overflow: hidden;
		cursor: pointer;
		transition: transform 0.3s, box-shadow 0.3s;
	}
	.video-gallery .video-item:hover {
		transform: translateY(-5px);
		box-shadow: 0 5px 15px rgba(0,0,0,0.2);
	}
	
	/* Video Wrapper */
	.video-gallery .video-wrapper {
		position: relative;
		width: 100%;
		aspect-ratio: 16/9;
		background: #000;
		overflow: hidden;
	}
	.video-gallery .video-wrapper img,
	.video-gallery .video-wrapper video {
		width: 100%;
		height: 100%;
		object-fit: cover;
		display: block;
	}
	
	/* Play Button */
	.video-gallery .play-button {
		position: absolute;
		top: 50%;
		left: 50%;
		transform: translate(-50%, -50%);
		pointer-events: none;
		transition: transform 0.3s;
	}
	.video-gallery .video-item:hover .play-button {
		transform: translate(-50%, -50%) scale(1.1);
	}
	
	/* Video Title */
	.video-gallery .video-title {
		padding: 15px;
		margin: 0;
		font-size: 16px;
		color: #333;
	}
	
	/* Lightbox */
	.video-lightbox {
		display: none;
		position: fixed;
		top: 0;
		left: 0;
		width: 100%;
		height: 100%;
		background: rgba(0, 0, 0, 0.95);
		z-index: 9999;
		align-items: center;
		justify-content: center;
		padding: 20px;
		animation: fadeIn 0.3s ease;
	}
	.video-lightbox.active {
		display: flex;
	}
	
	/* Lightbox Content */
	.lightbox-content {
		position: relative;
		width: 100%;
		max-width: 1200px;
		animation: scaleIn 0.3s ease;
	}
	
	/* Close Button */
	.lightbox-close {
		position: absolute;
		top: -40px;
		right: 0;
		background: transparent;
		border: none;
		color: white;
		font-size: 40px;
		cursor: pointer;
		line-height: 1;
		padding: 0;
		width: 40px;
		height: 40px;
		transition: transform 0.2s;
	}
	.lightbox-close:hover {
		transform: scale(1.2);
	}
	
	/* Video Container */
	.lightbox-video-container {
		position: relative;
		width: 100%;
		aspect-ratio: 16/9;
		background: #000;
		border-radius: 8px;
		overflow: hidden;
	}
	.lightbox-video-container video {
		width: 100%;
		height: 100%;
		display: block;
	}
	
	/* Lightbox Title */
	.lightbox-title {
		color: white;
		text-align: center;
		margin: 20px 0 0 0;
		font-size: 24px;
		font-weight: 600;
	}
	
	/* Animations */
	@keyframes fadeIn {
		from { opacity: 0; }
		to { opacity: 1; }
	}
	@keyframes scaleIn {
		from { transform: scale(0.9); opacity: 0; }
		to { transform: scale(1); opacity: 1; }
	}
	
	/* Responsive */
	@media (max-width: 768px) {
		.video-gallery {
			grid-template-columns: 1fr !important;
		}
		.lightbox-close {
			top: -50px;
			font-size: 50px;
		}
		.lightbox-title {
			font-size: 18px;
		}
	}
	</style>
	
	<script type="text/javascript">
	// Video data for lightbox
	var videoGallery<?php echo $galleryId; ?> = <?php
		$js_videos = [];
		foreach ($gallery['videos'] as $v) {
			$is_yt = (strpos($v['url'], 'youtube:') === 0);
			$js_videos[] = [
				'title' => $v['title'],
				'url'   => $v['url'],
				'thumb' => $v['thumb'],
				'yt_id' => $is_yt ? substr($v['url'], 8) : '',
			];
		}
		echo json_encode($js_videos);
	?>;
	
	// Open lightbox
	function openVideoLightbox<?php echo $galleryId; ?>(index) {
		var video	 = videoGallery<?php echo $galleryId; ?>[index];
		var lightbox  = document.getElementById('videoLightbox<?php echo $galleryId; ?>');
		var videoEl   = document.getElementById('lightboxVideo<?php echo $galleryId; ?>');
		var iframeEl  = document.getElementById('lightboxIframe<?php echo $galleryId; ?>');
		var sourceEl  = document.getElementById('lightboxSource<?php echo $galleryId; ?>');
		var titleEl   = document.getElementById('lightboxTitle<?php echo $galleryId; ?>');

		titleEl.textContent = video.title;

		if (video.yt_id) {
			// YouTube
			videoEl.style.display  = 'none';
			iframeEl.style.display = '';
			iframeEl.src = 'https://www.youtube.com/embed/' + video.yt_id + '?autoplay=1&rel=0';
		} else {
			// Local video
			iframeEl.style.display = 'none';
			iframeEl.src		   = '';
			videoEl.style.display  = '';
			sourceEl.src		   = video.url;
			videoEl.load();
			videoEl.play();
		}

		lightbox.classList.add('active');
		document.body.style.overflow = 'hidden';
	}
	
	// Close lightbox
	function closeVideoLightbox<?php echo $galleryId; ?>(event) {
		if (event.target.classList.contains('video-lightbox') || 
			event.target.classList.contains('lightbox-close') ||
			event.target.closest('.lightbox-close')) {
			
			var lightbox = document.getElementById('videoLightbox<?php echo $galleryId; ?>');
			var videoEl  = document.getElementById('lightboxVideo<?php echo $galleryId; ?>');
			var iframeEl = document.getElementById('lightboxIframe<?php echo $galleryId; ?>');

			// Stop local video
			videoEl.pause();
			videoEl.currentTime = 0;
			// Stop YouTube by clearing src
			iframeEl.src = '';

			lightbox.classList.remove('active');
			document.body.style.overflow = '';
			event.stopPropagation();
		}
	}
	
	// Close on ESC key
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape') {
			var lightbox = document.getElementById('videoLightbox<?php echo $galleryId; ?>');
			if (lightbox.classList.contains('active')) {
				closeVideoLightbox<?php echo $galleryId; ?>({target: lightbox});
			}
		}
	});
	</script>
	<?php
	return ob_get_clean();
}
