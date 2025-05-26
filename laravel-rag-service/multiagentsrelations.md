Multi-Agent System Schema Reference
This guide explains the database tables and API parameters for the multi-agent system, designed to combine datasets (agents) for analysis. Use it to understand how to structure the POST /api/multi-agents endpoint payload.
Overview

Agents: Individual datasets (e.g., Sales, Products, Payments) stored in Qdrant.
Multi-Agent: A named group of 2-10 agents (e.g., “BusinessInsights”).
Relations: User-defined links between agent pairs, using join keys (e.g., prod_ref).
System Suggestions: Automated join key suggestions to assist users.

Database Tables
1. agents

Purpose: Stores individual datasets (agents).
Columns:
id: Unique ID (e.g., 1).
name: Descriptive name (e.g., “Sales Data”).
vector_collection: Qdrant collection (e.g., “agent_1_vectors”).


Role: Source of agents for multi-agents.
Example:id=1, name="Sales Data", vector_collection="agent_1_vectors"
id=2, name="Products Data", vector_collection="agent_2_vectors"
id=3, name="Payments Data", vector_collection="agent_3_vectors"



2. multi_agents

Purpose: Stores a multi-agent combining 2-10 agents.
Columns:
id: Unique ID (e.g., 1).
name: Unique name (e.g., “BusinessInsights”).
agent_ids: JSON array of agent IDs (e.g., [1, 2, 3]).


Role: Tracks the group of agents.
Example:id=1, name="BusinessInsights", agent_ids=[1, 2, 3]



3. multi_agent_relations

Purpose: Stores user-defined links between agent pairs in a multi-agent.
Columns:
id: Unique ID.
multi_agent_id: Links to multi-agent (e.g., 1).
source_agent_id: Starting agent (e.g., 1).
target_agent_id: Connected agent (e.g., 2).
join_key: Linking field (e.g., “prod_ref”).
description: Optional note.
suggested_confidence: System confidence score (e.g., 0.95).


Role: Defines the chain (e.g., Sales → Products).
Example:id=1, multi_agent_id=1, source_agent_id=1, target_agent_id=2, join_key="prod_ref", suggested_confidence=0.95
id=2, multi_agent_id=1, source_agent_id=2, target_agent_id=3, join_key="id", suggested_confidence=0.4



4. agent_relations

Purpose: Stores system-suggested join keys for agent pairs.
Columns:
id: Unique ID.
source_agent_id: Starting agent (e.g., 1).
target_agent_id: Connected agent (e.g., 2).
join_key: Suggested field (e.g., “prod_ref”).
description: Optional note.
confidence: Score (e.g., 0.95, >0.8).


Role: Assists users with join key suggestions.
Example:id=1, source_agent_id=1, target_agent_id=2, join_key="prod_ref", confidence=0.95
id=2, source_agent_id=2, target_agent_id=3, join_key="id", confidence=0.4



API Parameters for POST /api/multi-agents
The endpoint creates a multi-agent. Parameters map to the tables:

name (string, max 100):

Sets multi_agents.name (e.g., “BusinessInsights”).
Example: "name": "BusinessInsights".


agent_ids (array, 2-10 integers):

Sets multi_agents.agent_ids (e.g., [1, 2, 3]).
Must exist in agents.id.
Example: "agent_ids": [1, 2, 3].


relations (array of objects, at least 1):

Creates rows in multi_agent_relations.
Each object:
source_agent_id: Starting agent ID (e.g., 1).
target_agent_id: Connected agent ID (e.g., 2).
join_key: Field to link them (e.g., “prod_ref”).


Example:"relations": [
    {"source_agent_id": 1, "target_agent_id": 2, "join_key": "prod_ref"},
    {"source_agent_id": 2, "target_agent_id": 3, "join_key": "id"}
]





How It Works

You Specify: name, agent_ids, and relations to create a multi-agent.
System Validates: Ensures agent_ids exist, relations are valid, and join_key is in Qdrant points.
System Suggests: JoinKeyDetector scores join_key (stored in suggested_confidence) and saves suggestions in agent_relations.

Visual Diagram
[agents]                 [multi_agents]
  id=1 Sales             id=1 BusinessInsights
  id=2 Products          agent_ids=[1, 2, 3]
  id=3 Payments

[multi_agent_relations]       [agent_relations]
  Sales → Products            Sales → Products
  join_key="prod_ref"         join_key="prod_ref"
  confidence=0.95             confidence=0.95
  Products → Payments         Products → Payments
  join_key="id"               join_key="id"
  confidence=0.4              confidence=0.4

