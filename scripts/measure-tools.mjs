// Measures what the tool list costs a client (phase K): the size of every tool's `tools/list` entry (name, title,
// description, input and output schema, annotations) per area, for a bare WordPress site and for typical sites.
// Sizes are exact bytes of the JSON a client receives; tokens are an estimate (bytes / 4), not a measurement.
// Usage: node scripts/measure-tools.mjs   → prints a Markdown report and writes build/measure-tools/report.md
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
import { cleanPlaygroundTemp } from './playground-temp.mjs';

const root = fileURLToPath(new URL('..', import.meta.url));
const out = join(root, 'build', 'measure-tools');
rmSync(out, { recursive: true, force: true });
mkdirSync(out, { recursive: true });

const php = `<?php
require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
Kuduy\\Mcp\\Plugin::boot();
$tools = array();
foreach ( Kuduy\\Mcp\\Mcp\\ToolRegistry::create()->all() as $tool ) {
	$input                = $tool->input_schema();
	$input['properties']  = (object) ( $input['properties'] ?? array() );
	$output               = $tool->output_schema();
	$output['properties'] = (object) ( $output['properties'] ?? array() );
	$entry                = array(
		'name'         => $tool->name(),
		'title'        => $tool->title(),
		'description'  => $tool->description(),
		'inputSchema'  => $input,
		'outputSchema' => $output,
		'annotations'  => (object) $tool->annotations(),
	);
	$tools[] = array(
		'name'        => $tool->name(),
		'class'       => get_class( $tool ),
		'scope'       => $tool->scope(),
		'available'   => true === $tool->is_available(),
		'bytes'       => strlen( (string) wp_json_encode( $entry ) ),
		'description' => strlen( $tool->description() ),
		'input'       => strlen( (string) wp_json_encode( $input ) ),
		'output'      => strlen( (string) wp_json_encode( $output ) ),
	);
}
file_put_contents( '/kuduy-out/tools.json', wp_json_encode( $tools ) );`;

writeFileSync(join(out, 'blueprint.json'), JSON.stringify({
  preferredVersions: { php: '8.3', wp: '7.1' },
  steps: [
    { step: 'defineWpConfigConsts', consts: { AUTOMATIC_UPDATER_DISABLED: true } },
    { step: 'runPHP', code: php },
  ],
}));
const cli = join(root, 'node_modules', '@wp-playground', 'cli', 'wp-playground.js');
const run = spawnSync(process.execPath, [cli, 'run-blueprint', '--wp=7.1', '--php=8.3',
  `--blueprint=${join(out, 'blueprint.json')}`,
  '--mount-dir', root, '/wordpress/wp-content/plugins/kuduy-mcp',
  '--mount-dir', out, '/kuduy-out',
], { cwd: root, stdio: 'inherit' });
cleanPlaygroundTemp(run.pid);

const file = join(out, 'tools.json');
if (!existsSync(file)) {
  console.error('No tools.json: the Playground step did not run.');
  process.exit(1);
}
const tools = JSON.parse(readFileSync(file, 'utf8')).filter((t) => t.scope !== 'kuduy:code');
const area = (t) => t.class.split('\\').slice(-2, -1)[0] || 'Core';
const sum = (list, key = 'bytes') => list.reduce((n, t) => n + t[key], 0);
const kb = (n) => (n / 1024).toFixed(1);
const tok = (n) => Math.round(n / 4).toLocaleString('en-US');

const areas = new Map();
for (const t of tools) {
  if (!areas.has(area(t))) areas.set(area(t), []);
  areas.get(area(t)).push(t);
}
const bare = tools.filter((t) => t.available);
// A site = the tools of bare WordPress plus the areas of the plugins it runs.
const site = (...names) => {
  const set = new Set(bare.map((t) => t.name));
  for (const n of names) for (const t of areas.get(n) || []) set.add(t.name);
  return tools.filter((t) => set.has(t.name));
};
const scenarios = [
  ['Bare WordPress (block theme)', bare],
  ['Blog: + one SEO plugin + forms + cache', site('Seo', 'Forms', 'Cache')],
  ['Elementor site: + Elementor + SEO + forms + cache', site('Elementor', 'Seo', 'Forms', 'Cache')],
  ['Shop: + WooCommerce + Elementor + SEO + forms + ACF + cache', site('Woo', 'Elementor', 'Seo', 'Forms', 'Acf', 'Cache')],
  ['Everything registered (never happens on one site)', tools],
];

const lines = [];
lines.push(`Measured ${new Date().toISOString().slice(0, 10)} on ${tools.length} tools (code-execution tools left out). Bytes are the exact JSON of each tool's \`tools/list\` entry; tokens are estimated as bytes / 4.`, '');
lines.push('| Site | Tools | List size | Est. tokens |', '|---|---:|---:|---:|');
for (const [name, list] of scenarios) lines.push(`| ${name} | ${list.length} | ${kb(sum(list))} KB | ${tok(sum(list))} |`);
lines.push('', '| Area | Tools | List size | Avg per tool | Description | Input schema | Output schema |', '|---|---:|---:|---:|---:|---:|---:|');
for (const [name, list] of [...areas].sort((a, b) => sum(b[1]) - sum(a[1]))) {
  const total = sum(list);
  const pct = (key) => `${Math.round((100 * sum(list, key)) / total)}%`;
  lines.push(`| ${name} | ${list.length} | ${kb(total)} KB | ${Math.round(total / list.length)} B | ${pct('description')} | ${pct('input')} | ${pct('output')} |`);
}
const all = sum(tools);
lines.push('', `Across all tools: descriptions ${Math.round((100 * sum(tools, 'description')) / all)}%, input schemas ${Math.round((100 * sum(tools, 'input')) / all)}%, output schemas ${Math.round((100 * sum(tools, 'output')) / all)}% of the list.`);
lines.push('', 'Ten largest entries: ' + [...tools].sort((a, b) => b.bytes - a.bytes).slice(0, 10).map((t) => `\`${t.name}\` ${kb(t.bytes)} KB`).join(', ') + '.');
const report = lines.join('\n') + '\n';
writeFileSync(join(out, 'report.md'), report);
console.log('\n' + report);
