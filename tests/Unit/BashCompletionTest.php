<?php

function runContainerToolsCompletion(array $words, ?string $podmanBinary = null): array
{
    $arguments = implode(' ', array_map('escapeshellarg', $words));
    $completion = escapeshellarg(dirname(__DIR__, 2) . '/share/bash-completion/container-tools');
    $script = "source $completion; COMP_WORDS=($arguments); COMP_CWORD=\$((\${#COMP_WORDS[@]} - 1)); _container_tools; printf '%s\\n' \"\${COMPREPLY[@]}\"";
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $environment = $podmanBinary === null ? null : ['PODMAN_BINARY' => $podmanBinary];
    $process = proc_open(['/bin/bash', '-c', $script], $descriptors, $pipes, null, $environment);

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start Bash for completion test.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    if ($status !== 0) {
        throw new RuntimeException("Bash completion failed: $stderr");
    }

    return array_values(array_filter(explode("\n", trim($stdout)), 'strlen'));
}

test('Bash completion lists commands and command options', function () {
    $completion = escapeshellarg(dirname(__DIR__, 2) . '/share/bash-completion/container-tools');
    exec("/bin/bash -c 'source $completion; complete -p container-tools'", $completionOptions);
    exec('/bin/bash --version | head -1', $bashVersion);
    preg_match('/version (\d+)\.(\d+)/', $bashVersion[0], $version);
    $supportsNoSort = (int) $version[1] > 4 || ((int) $version[1] === 4 && (int) $version[2] >= 4);

    if ($supportsNoSort) {
        expect(implode(' ', $completionOptions))->toContain('-o nosort');
    } else {
        expect(implode(' ', $completionOptions))->not->toContain('-o nosort');
    }

    $rootSuggestions = runContainerToolsCompletion(['container-tools', '']);
    expect($rootSuggestions)->toContain('create');
    expect($rootSuggestions)->toContain('cp');
    expect($rootSuggestions)->toContain('host-ports');
    expect(array_search('create', $rootSuggestions, true))->toBeLessThan(array_search('--help', $rootSuggestions, true));

    expect(runContainerToolsCompletion(['container-tools', 'create', '--p']))
        ->toContain('--publish')
        ->toContain('--pasta-auto-publish');
});

test('Bash completion keeps global options available after a command', function () {
    $suggestions = runContainerToolsCompletion(['container-tools', 'create', '']);

    expect($suggestions)
        ->toContain('--verbose')
        ->toContain('--quiet')
        ->toContain('--dry-run')
        ->and(array_search('--name', $suggestions, true))->toBeLessThan(array_search('--verbose', $suggestions, true));

    expect($suggestions)->not->toContain('-v')->not->toContain('-q')->not->toContain('-d');
});

test('Bash completion hides options that were already supplied', function () {
    $suggestions = runContainerToolsCompletion(['container-tools', 'create', '--verbose', '']);

    expect($suggestions)
        ->not->toContain('--verbose')
        ->toContain('--quiet')
        ->toContain('--dry-run');
});

test('Bash completion supports file options with equals syntax', function () {
    $prefix = dirname(__DIR__, 2) . '/examples/caddy/Cad';
    expect(runContainerToolsCompletion(['container-tools', 'build', "--file=$prefix"]))
        ->toContain("--file={$prefix}dyfile");
});

test('Bash completion lists containers from the selected Podman host', function () {
    $fakePodman = tempnam(sys_get_temp_dir(), 'container-tools-podman-');
    file_put_contents($fakePodman, "#!/bin/bash\nprintf '%s\\n' test-container\n");
    chmod($fakePodman, 0755);

    try {
        expect(runContainerToolsCompletion(['container-tools', 'status', ''], $fakePodman))
            ->toContain('test-container');
    } finally {
        unlink($fakePodman);
    }
});
