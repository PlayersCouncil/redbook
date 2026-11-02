import { execFileSync } from 'child_process';

// Runs bin/console in the app container (the stack the tests drive).
export const container = process.env.E2E_APP_CONTAINER ?? 'redbook';

export function appConsole(args: string[], input?: string): string {
  return execFileSync('docker', ['exec', '-i', '-u', 'www-data', container, 'php', 'bin/console', ...args], { encoding: 'utf8', input });
}
