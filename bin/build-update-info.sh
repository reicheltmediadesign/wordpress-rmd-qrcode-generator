#!/usr/bin/env bash
# Writes dist/update.json, the metadata file plugin-update-checker reads.
# Values come from the plugin header and CHANGELOG.md, so the release notes on
# GitHub can be edited freely without breaking updates.
set -euo pipefail

cd "$(dirname "$0")/.."
source bin/plugin.env

mkdir -p dist
SLUG="$SLUG" MAIN_FILE="$MAIN_FILE" REPO="$REPO" node - <<'NODE'
const fs = require( 'fs' );

const { SLUG: slug, MAIN_FILE: mainFile, REPO: repo } = process.env;
const header = fs.readFileSync( mainFile, 'utf8' );
const changelog = fs.readFileSync( 'CHANGELOG.md', 'utf8' );

const field = ( name ) => {
	const match = new RegExp( '^\\s*\\*?\\s*' + name + ':\\s*(.+)$', 'mi' ).exec( header );
	return match ? match[ 1 ].trim() : '';
};

const version = field( 'Version' );

// Changelog section of this version from CHANGELOG.md → simple HTML.
const sectionMatch = new RegExp( '^## \\[' + version.replace( /\./g, '\\.' ) + '\\][^\\n]*\\n([\\s\\S]*?)(?=^## \\[|(?![\\s\\S]))', 'm' ).exec( changelog );
const escapeHtml = ( s ) => s.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
const inline = ( s ) => escapeHtml( s ).replace( /`([^`]+)`/g, '<code>$1</code>' ).replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' );
let changelogHtml = '';
if ( sectionMatch ) {
	let inList = false;
	for ( const line of sectionMatch[ 1 ].split( /\r?\n/ ) ) {
		if ( /^### /.test( line ) ) {
			if ( inList ) { changelogHtml += '</ul>'; inList = false; }
			changelogHtml += '<h4>' + inline( line.replace( /^### /, '' ) ) + '</h4>';
		} else if ( /^- /.test( line ) ) {
			if ( ! inList ) { changelogHtml += '<ul>'; inList = true; }
			changelogHtml += '<li>' + inline( line.replace( /^- /, '' ) ) + '</li>';
		}
	}
	if ( inList ) { changelogHtml += '</ul>'; }
}
changelogHtml += '<p><a href="' + repo + '/blob/main/CHANGELOG.md">' + escapeHtml( 'Full changelog on GitHub' ) + '</a></p>';

const info = {
	name: field( 'Plugin Name' ),
	slug,
	version,
	download_url: repo + '/releases/download/v' + version + '/' + slug + '.zip',
	homepage: repo,
	requires: field( 'Requires at least' ),
	requires_php: field( 'Requires PHP' ),
	last_updated: new Date().toISOString().slice( 0, 19 ).replace( 'T', ' ' ),
	author: '<a href="https://reicheltmedia.design">reichelt media.design</a>',
	author_homepage: 'https://reicheltmedia.design',
	sections: {
		description: '<p>' + escapeHtml( field( 'Description' ) ) + '</p>',
		changelog: changelogHtml,
	},
};

fs.writeFileSync( 'dist/update.json', JSON.stringify( info, null, 2 ) + '\n' );
console.log( 'Wrote dist/update.json for version ' + version );
NODE
