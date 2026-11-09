# Build status

The initial release candidate contains the signed bridge, hosted support client, backend-driven API client and approved-guide publisher. The unreleased 0.2.0 work adds the signed customer profile (resources, signals) and support access elevation; Assistant's consumption of `/profile` is tracked in the Assistant app. CI is the release gate for PHP 8.4/8.5 and Laravel 12/13.

Host applications must still configure the Assistant workspace, widget, API key, connector secret and authorization policies. Live product acceptance is tracked in the consuming services. Knowledge deletion reconciliation is not included; withdrawals require explicit platform removal.
