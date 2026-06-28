// Run PHP inside the wp-env site via the `wpenv` wrapper, returning stdout.
//
// The PHP is base64-wrapped so it travels as a single space-free argument —
// immune to shell- and wp-env-level word splitting. The wpenv wrapper sends its
// own status lines to stderr, so stdout is just what our PHP echoes.

const { execFileSync } = require( 'child_process' );

function wpEval( php ) {
	const b64 = Buffer.from( php, 'utf8' ).toString( 'base64' );
	const wrapper = `eval(base64_decode("${ b64 }"));`;
	return execFileSync(
		'wpenv',
		[ 'run', 'cli', 'wp', 'eval', wrapper ],
		{ encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] }
	).trim();
}

// Convenience: run PHP that echoes JSON and parse it (last non-empty line).
function wpEvalJson( php ) {
	const out = wpEval( php );
	const line = out.split( '\n' ).filter( Boolean ).pop() || '';
	return JSON.parse( line );
}

module.exports = { wpEval, wpEvalJson };
