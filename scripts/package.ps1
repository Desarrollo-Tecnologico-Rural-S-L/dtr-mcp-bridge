$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path -Parent $PSScriptRoot
$taskSource = Join-Path $taskRoot 'dtr-mcp-bridge'
$taskOutput = Join-Path $taskRoot 'dist'
New-Item -ItemType Directory -Force -Path $taskOutput | Out-Null
$taskArchive = Join-Path $taskOutput 'dtr-mcp-bridge-1.3.7-rc.9.zip'
Add-Type -AssemblyName System.IO.Compression.FileSystem
Add-Type -AssemblyName System.IO.Compression
$taskStreamOut = [IO.File]::Create($taskArchive)
try {
    $taskWriter = [IO.Compression.ZipArchive]::new($taskStreamOut, [IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($taskFile in (Get-ChildItem -LiteralPath $taskSource -File | Sort-Object Name)) {
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($taskWriter, $taskFile.FullName, "dtr-mcp-bridge/$($taskFile.Name)") | Out-Null
        }
    } finally { $taskWriter.Dispose() }
} finally { $taskStreamOut.Dispose() }
$taskZip = [IO.Compression.ZipFile]::OpenRead($taskArchive)
try {
    $taskEntries = @($taskZip.Entries | Where-Object { $_.Name })
    if ($taskEntries.Count -ne 6) { throw 'Unexpected package contents' }
    if (!$taskZip.GetEntry('dtr-mcp-bridge/dtr-mcp-bridge.php')) { throw 'Missing plugin entry point' }
    foreach ($taskEntry in $taskEntries) {
        $taskName = $taskEntry.FullName
        if ($taskName -notmatch '^dtr-mcp-bridge/[a-z0-9-]+\.(php|json)$') { throw 'Unexpected entry path' }
        $taskStream = $taskEntry.Open()
        $taskHasher = [Security.Cryptography.SHA256]::Create()
        try { $taskActual = [BitConverter]::ToString($taskHasher.ComputeHash($taskStream)).Replace('-','') }
        finally { $taskStream.Dispose(); $taskHasher.Dispose() }
        $taskExpected = (Get-FileHash -LiteralPath (Join-Path $taskRoot $taskName) -Algorithm SHA256).Hash
        if ($taskActual -ne $taskExpected) { throw 'ZIP source mismatch' }
    }
} finally { $taskZip.Dispose() }
$taskDigest = (Get-FileHash -LiteralPath $taskArchive -Algorithm SHA256).Hash.ToLowerInvariant()
[IO.File]::WriteAllText((Join-Path $taskOutput 'SHA256SUMS.txt'), "$taskDigest  dtr-mcp-bridge-1.3.7-rc.9.zip`n")
Write-Output "Verified package: $taskArchive"
Write-Output "SHA256: $taskDigest"
