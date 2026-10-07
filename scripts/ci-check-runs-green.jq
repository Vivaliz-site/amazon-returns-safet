.total_count > 0
and (
  .check_runs
  | group_by(.name)
  | map(max_by(.id))
  | all(.status == "completed" and .conclusion == "success")
)
