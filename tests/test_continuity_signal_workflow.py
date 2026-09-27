from pathlib import Path


def test_continuity_signal_uses_env_boundary_for_github_context() -> None:
    workflow = (
        Path(__file__).resolve().parents[1]
        / ".github"
        / "workflows"
        / "continuity-signal.yml"
    ).read_text(encoding="utf-8")
    run_block = workflow.split("run: |", 1)[1]
    assert "${{ github.ref_name }}" not in run_block
    assert "${{ github.sha }}" not in run_block
    assert 'CONTINUITY_REF_NAME: ${{ github.ref_name }}' in workflow
    assert 'CONTINUITY_SHA: ${{ github.sha }}' in workflow
    assert '"$CONTINUITY_REF_NAME"' in run_block
    assert '"$CONTINUITY_SHA"' in run_block
