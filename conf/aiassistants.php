<?php

/*
 * AI assistants whose referrals are their own medium, `ai-agent`, and their own
 * channel, AI Agent -- Google Analytics' ai-assistant / AI Assistant, which OWA
 * names differently and still recognises when a site tags it. The assistants
 * GA names: ChatGPT, Gemini, Claude, Copilot, DeepSeek and Grok.
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
