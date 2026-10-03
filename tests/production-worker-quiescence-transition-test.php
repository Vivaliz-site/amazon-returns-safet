<?php
declare(strict_types=1);

function qtAssert(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException($message);
}

$root=dirname(__DIR__);
$helper=$root.'/scripts/quiesce-production-workers.sh';
$tmp=sys_get_temp_dir().'/qwt-'.bin2hex(random_bytes(5));
mkdir($tmp,0700,true);
$log=$tmp.'/events.log';
$browserStates=$tmp.'/browser.states';
file_put_contents($browserStates,"activating\ninactive\n");

$bash=<<<'BASH'
set -euo pipefail
source "$HELPER"
qw_scalar(){
    case "$2" in
        *amazon_return_tenants*) printf '7\n' ;;
        *amazon_return_connections*) printf '11\n' ;;
        *) return 1 ;;
    esac
}
qw_processing_count(){ printf '0\n'; }
systemctl(){
    printf '%s\n' "$*" >> "$LOG"
    if [[ "$1" == "show" ]]; then
        unit="$(printf '%s\n' "$@" | tail -n1)"
        if [[ "$unit" == "amazon-returns-safet.service" ]]; then
            printf 'active\n'
            return 0
        fi
        if [[ "$unit" == "amazon-returns-seller-central-browser.service" ]]; then
            state="$(head -n1 "$BROWSER_STATES")"
            printf '%s\n' "$state"
            if [[ "$state" == "activating" ]]; then
                tail -n +2 "$BROWSER_STATES" > "$BROWSER_STATES.next"
                mv "$BROWSER_STATES.next" "$BROWSER_STATES"
            fi
            return 0
        fi
        printf 'inactive\n'
        return 0
    fi
    case "$1" in
        is-active)
            unit="$(printf '%s\n' "$@" | tail -n1)"
            [[ "$unit" == "amazon-returns-seller-central-browser.timer" ]] && return 0
            return 1
            ;;
        *) return 0 ;;
    esac
}
sleep(){ printf 'sleep %s\n' "$1" >> "$LOG"; }
AMAZON_RETURNS_QUIESCE_MARKER="$(dirname "$LOG")/quiesce.marker" \
AMAZON_RETURNS_QUIESCE_TIMEOUT_SECONDS=10 \
AMAZON_RETURNS_QUIESCE_POLL_SECONDS=1 \
quiesce_workers amazon_returns_safet shopvivaliz amazon-br-primary
BASH;

$command='HELPER='.escapeshellarg($helper)
    .' LOG='.escapeshellarg($log)
    .' BROWSER_STATES='.escapeshellarg($browserStates)
    .' bash -c '.escapeshellarg($bash).' 2>&1';
exec($command,$output,$exitCode);
qtAssert($exitCode===0,"Transition-safe quiescence failed:\n".implode("\n",$output));
$events=(string)file_get_contents($log);
$sleepPos=strpos($events,'sleep 1');
$freezeMainPos=strpos($events,'freeze amazon-returns-safet.service');
qtAssert($sleepPos!==false,'Quiescence must wait while a worker unit is transitioning.');
qtAssert($freezeMainPos!==false && $sleepPos<$freezeMainPos,'No unit may be frozen before transitioning workers become stable.');
qtAssert(!str_contains($events,'freeze amazon-returns-seller-central-browser.service'),'Browser oneshot that finishes while transitioning must not be frozen after it becomes inactive.');
qtAssert(str_contains($events,'stop amazon-returns-safet.service'),'Stable main daemon must still be stopped after a safe freeze.');

@unlink($browserStates);
@unlink($log);
@rmdir($tmp);
echo "production-worker-quiescence-transition-test: OK\n";
