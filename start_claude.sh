   #!/bin/bash

   # 1. Provide your live API parameters
   export ANTHROPIC_API_KEY="sk-sIiTrFgEvMuyicJqL8Qf6lQLpwobpUQLL4TNGcYTKd7KVpIG"
   export ANTHROPIC_BASE_URL="https://agentrouter.org/"

   # 2. Set the exact identifier for the High-Effort Reasoning tier
   # Choose ONE of these valid options:
   export ANTHROPIC_MODEL="claude-opus-4-6"
   
   # Or if you prefer DeepSeek's high effort reasoning model:
   # export ANTHROPIC_MODEL="agentrouter/deepseek-r1"

   # 3. Launch Claude Code natively
   claude
