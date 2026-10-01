<?php

/*
 * AI assistants whose referrals are their own medium, `ai-agent`, and their own
 * channel, AI Agent. A visit a site tags with medium `ai-assistant` is AI Agent
 * too (conf/channels.php).
 *
 * Each `domain` is matched as whole labels of the referring host or tagged
 * source (Classes\Cube\SiteLists), so a subdomain matches and a longer name
 * does not. Extend it with a file of the same name in the data directory.
 */
return [
	['domain' => 'chatgpt.com'],
	['domain' => 'chat.openai.com'],
	['domain' => 'gemini.google.com'],
	['domain' => 'claude.ai'],
	['domain' => 'copilot.microsoft.com'],
	['domain' => 'chat.deepseek.com'],
	['domain' => 'grok.com'],
];

?>
